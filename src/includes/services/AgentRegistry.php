<?php
/**
 * <module_context>
 *     <name>AgentRegistry</name>
 *     <description>Management of the AI agent manifest and installation logic.</description>
 *     <dependencies>LogService, ConfigService, AtomicWriteService</dependencies>
 *     <constraints>Under 150 lines. Handles versioning and discovery.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Sources\SourceResolver;

class AgentRegistry {
    const MANIFEST_FILE = "/boot/config/plugins/unraid-aicliagents/agents.json";
    const VERSIONS_FILE = "/boot/config/plugins/unraid-aicliagents/versions.json";
    const AGENT_BASE    = "/usr/local/emhttp/plugins/unraid-aicliagents/agents";

    /**
     * The character class every legitimate agent id already satisfies —
     * lowercase letters, digits, hyphens, 1-64 chars, never starting with a
     * hyphen. Matches the pattern already used by sibling code that treats an
     * agent id as filesystem-path input: UpgradeRelaunchService,
     * PaneInputRules, AgentUpgradeAdmissionService, PendingAgentUpgradeService,
     * TerminalHandler, AgentHandler. Not introducing a new convention — reusing
     * the one this codebase already settled on.
     */
    private const AGENT_ID_RE = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Resolve the override once per operation so tests and live writes share the same path. */
    private static function versionsPath(): string {
        return getenv('AICLI_VERSIONS_FILE') ?: self::VERSIONS_FILE;
    }

    /**
     * Serialize the versions.json read/modify/write transaction.
     *
     * A temp-file rename prevents torn JSON, but it does not prevent a stale
     * reader from replacing a newer channel selection. The channel selector,
     * install discovery, and self-heal paths all use this lock so they merge
     * their change with the current file contents before writing.
     */
    private static function withVersionsLock(callable $callback) {
        $path = self::versionsPath();
        $lockPath = $path . '.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $fp = @fopen($lockPath, 'c');
        if ($fp === false || !@flock($fp, LOCK_EX)) {
            if (is_resource($fp)) @fclose($fp);
            LogService::log("Unable to lock versions file: $lockPath", LogService::LOG_WARN, "AgentRegistry");
            return null;
        }

        try {
            return $callback();
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /** Apply one read/modify/write mutation while holding the versions lock. */
    private static function mutateVersions(callable $mutator): bool {
        return self::withVersionsLock(function () use ($mutator): bool {
            $versions = self::getVersions();
            $mutator($versions);
            if (!AtomicWriteService::writeJson(self::versionsPath(), $versions)) {
                LogService::log("Unable to save versions file", LogService::LOG_WARN, "AgentRegistry");
                return false;
            }
            return true;
        }) === true;
    }

    /**
     * STRUCTURAL RULE (docs/specs/AGENT_SELF_UPDATE_SUPPRESSION.md, 2026-09-10):
     * every agent this plugin defines must have a self-update decision — EITHER
     * listed here (a verified suppression is applied in that agent's own
     * getDefaultAgents() entry: an env var in `default_envs`, a CLI flag in
     * `plugin_args`, or a config-file key in `default_settings`)
     * OR listed in SELF_UPDATE_EXEMPT with a reason. RegressionGuardsTest::
     * testEveryRegisteredAgentHasSelfUpdateDecision fails CI if a newly added
     * agent has neither. This exists because an agent's own in-TUI "update
     * available, install now?" prompt writes into the plugin-managed overlay's
     * flash-backed upper layer, silently desyncs the plugin's version tracking
     * (versions.json), and bypasses the plugin's upgrade machinery (closed-set
     * relaunch, layer activation, rollback) entirely — observed live on
     * 2026-09-10 for kimi-code (see that agent's entry below).
     * @var string[]
     */
    const SELF_UPDATE_SUPPRESSED = [
        'claude-code', 'opencode', 'kilocode', 'pi-coder', 'codex-cli',
        'factory-cli', 'antigravity-cli', 'grok-build', 'kimi-code',
        // 2026-09-24: COPILOT_AUTO_UPDATE=false, verified in the binary itself.
        'gh-copilot',
        // Suppressed through `default_settings` (a settings.json key) rather than
        // an env var or CLI flag — see AgentSettingsSeedService.
        'gemini-cli', 'qwen-code',
    ];

    /**
     * Agents with NO verified, wireable self-update suppression today. Each
     * reason states whether a real mechanism exists upstream and, if so, why
     * this plugin cannot apply it yet (see docs/specs/AGENT_SELF_UPDATE_SUPPRESSION.md
     * for the full research trail and citations behind each entry).
     * @var array<string,string> agentId => reason
     */
    const SELF_UPDATE_EXEMPT = [
        'nanocoder' => 'No self-update mechanism exists to suppress — the published '
            . 'npm package.json (registry.npmjs.org, checked 2026-09-10) ships no '
            . 'update-notifier or auto-updater dependency of any kind.',
        'goose' => 'The installed CLI binary (github_release asset) has no automatic '
            . 'self-update at all — updating requires the user to explicitly run '
            . '`goose update` (https://goose-docs.ai/docs/guides/updating-goose/). '
            . 'GOOSE_DISABLE_AUTO_DOWNLOAD only gates the separate Electron desktop '
            . "app's auto-updater, which this plugin does not install.",
    ];

    /**
     * Root directory under which every agent's overlay-mounted directory
     * lives. Today this is just AGENT_BASE — the indirection exists so Phase
     * 2+ of docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md (2026-09-09) can change
     * what this returns (e.g. a version-qualified root) in ONE place instead
     * of in every one of the ~20 files that used to read AGENT_BASE directly
     * or repeat its literal value. No behaviour change today: same string,
     * same callers, same mounts.
     */
    public static function agentBase(): string {
        return self::AGENT_BASE;
    }

    /**
     * The overlay-mounted directory for one agent. This is the resolver Phase
     * 1 of docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md (2026-09-09) introduces:
     * every PHP call site that used to build `AgentRegistry::AGENT_BASE . "/$id"`
     * (or re-derive the same literal independently) now calls this instead, so
     * a later phase can make this version-aware without touching those callers
     * again. Today it returns exactly what the inline concatenation always did.
     *
     * $agentId is validated against AGENT_ID_RE before it reaches a filesystem
     * path — an agent id is frequently only one hop from request input (e.g.
     * $_GET['agentId']), and this is the single place that value funnels
     * through on its way to a real path, so it is the right place to refuse a
     * hostile one (path traversal, null bytes, empty string) rather than build
     * a bad path and let it propagate.
     *
     * @throws \InvalidArgumentException if $agentId is empty or contains
     *         anything outside the registry's own id character class.
     */
    public static function agentPath(string $agentId): string {
        if (!preg_match(self::AGENT_ID_RE, $agentId)) {
            throw new \InvalidArgumentException("AgentRegistry::agentPath: invalid agent id '$agentId'");
        }
        return self::agentBase() . "/$agentId";
    }

    /**
     * Where an install WRITES, as opposed to where the agent RUNS.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15). These were
     * the same path until now, and that identity is precisely why an upgrade had
     * to close every session first: `npm install` ran straight against the merged
     * view of the live mount, so the new version landed in the running sessions'
     * own writable layer before any mount swap was even considered.
     *
     * With a staging overlay bound (StorageMountService::stageAgentInstall) the
     * two separate: the install writes into a layer of its own over the same
     * read-only lowers, and the version in service is untouched until the new
     * generation is activated. Every other caller keeps using agentPath().
     *
     * The override is process-scoped and set for the duration of one install, so
     * an AgentSource keeps deriving its own paths rather than having an install
     * root threaded through an interface every source would have to change.
     */
    private static ?string $installRootOverride = null;

    /** Point installs at $path (null clears). Callers MUST clear it on every exit path. */
    public static function setInstallRoot(?string $path): void {
        self::$installRootOverride = ($path === null || $path === '') ? null : rtrim($path, '/');
    }

    /** The staging root currently in force, or null when installs go to the live mount. */
    public static function installRoot(): ?string {
        return self::$installRootOverride;
    }

    /**
     * The directory an install should write this agent into: the staging mount
     * when one is bound, otherwise exactly what agentPath() has always returned.
     *
     * @throws \InvalidArgumentException if $agentId is empty or malformed.
     */
    public static function agentInstallPath(string $agentId): string {
        if (!preg_match(self::AGENT_ID_RE, $agentId)) {
            throw new \InvalidArgumentException("AgentRegistry::agentInstallPath: invalid agent id '$agentId'");
        }
        return self::$installRootOverride ?? self::agentPath($agentId);
    }

    /**
     * The registry binary as the install sees it: remapped into the staging root
     * while a side-by-side install is in progress, unchanged otherwise.
     *
     * 2026-09-24 (docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "record the version
     * that was installed"): every source's version probe must use this. The
     * registry `binary` names the STABLE path, which during a staged install is
     * still the version in service. GithubReleaseSource probed that path, so a
     * goose downgrade 1.52.0 -> 1.51.0 recorded 1.52.0 (the old binary) while
     * the new generation ran 1.51.0 — the record lagged one change behind.
     */
    public static function installBinaryPath(string $agentId, string $binary): string {
        $stable = self::agentPath($agentId);
        $install = self::agentInstallPath($agentId);
        if ($install === $stable || $binary === '') return $binary;
        if ($binary === $stable) return $install;
        $prefix = $stable . '/';
        if (strncmp($binary, $prefix, strlen($prefix)) === 0) {
            return $install . substr($binary, strlen($stable));
        }
        return $binary;
    }

    /**
     * The version-qualified directory one agent generation is (or would be)
     * mounted at — agents/.versions/<id>/<generation>. SIDE_BY_SIDE_AGENT_INSTALLS.md
     * Phase 2 (2026-09-09): the shell mount pipeline (storage_ops.sh op_mount /
     * resolve_paths.sh agent_versioned_mount + agent_activate_stable_symlink) is
     * the actual authority that creates one of these and activates it; this PHP
     * twin is for read-only callers that need to reason about the real,
     * version-qualified path without duplicating the shell's arithmetic (status
     * surfaces, a future GC view). It does NOT mount anything.
     *
     * agentPath() — the STABLE symlink once an agent is migrated — remains what
     * every other caller should keep using; only code that genuinely needs the
     * real, generation-qualified path belongs here (see also agentLiveMountTarget()
     * below, for "whatever the stable name resolves to RIGHT NOW").
     *
     * @throws \InvalidArgumentException on an invalid agent id or generation id.
     */
    public static function agentVersionedPath(string $agentId, string $generationId): string {
        if (!preg_match(self::AGENT_ID_RE, $agentId)) {
            throw new \InvalidArgumentException("AgentRegistry::agentVersionedPath: invalid agent id '$agentId'");
        }
        // Mirrors generation.sh's own sanitisation of a generation id part
        // (_aicli_sanitize_generation_part): the id is a hash + a sanitised
        // layer-basename tag, never arbitrary, so a strict allow-list is safe.
        $safeGen = preg_replace('/[^A-Za-z0-9._-]/', '', $generationId);
        if ($safeGen === null || $safeGen === '') {
            throw new \InvalidArgumentException("AgentRegistry::agentVersionedPath: invalid generation id '$generationId'");
        }
        return self::agentBase() . "/.versions/$agentId/$safeGen";
    }

    /**
     * The directory agentPath()'s stable name ACTUALLY resolves to right now —
     * as opposed to agentPath() itself, which every ordinary caller should keep
     * using unchanged. SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): once
     * an agent is migrated, agentPath() is a symlink into agentVersionedPath(),
     * and a handful of callers need the REAL path specifically because they are
     * about to match it, literally, against /proc/mounts — which records the
     * kernel's actual mount target and never a symlink's own name (today: only
     * liveAgentLowerdir() below). realpath() is exactly the right primitive: it
     * returns the stable path itself, unchanged, for a not-yet-migrated agent
     * (still a real directory — byte-identical to Phase 1) and for an agent
     * that was never installed (nonexistent path — realpath() returns false,
     * so this falls back to the stable path exactly as it would have resolved
     * before Phase 2 existed).
     */
    public static function agentLiveMountTarget(string $agentId): string {
        $stable = self::agentPath($agentId);
        $real = @realpath($stable);
        return $real !== false ? $real : $stable;
    }

    /**
     * How many versions of one agent may be mounted at the same time.
     *
     * docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3, R6. Two — the one being
     * activated and one aging out. This is a much tighter ceiling than the
     * rollback buffer the plugin keeps for its own code, and deliberately so:
     * a plugin generation is a directory tree of a few megabytes, an agent
     * generation is a live overlay plus a writable layer measured at 255-450 MB
     * per agent on this box, against roughly 6 GB free on a write-endurance-
     * limited USB device. An uncapped overlap is a realistic route to an
     * out-of-space install, not a theoretical one.
     */
    public const MAX_CONCURRENT_GENERATIONS = 2;

    /**
     * Every generation of this agent with an overlay bound to it right now.
     *
     * Reads /proc/mounts rather than listing directories, because a directory
     * under .versions/ proves nothing about whether anything is mounted on it —
     * a crash between the mount and the symlink flip leaves one behind, and
     * treating that as a live version would refuse upgrades forever.
     *
     * @param string|null $mountsText /proc/mounts content; injected by tests.
     * @return array<int,string> generation ids, in mount-table order
     */
    public static function mountedGenerations(string $agentId, ?string $mountsText = null): array {
        if (!preg_match(self::AGENT_ID_RE, $agentId)) return [];
        $text = $mountsText ?? (string)@file_get_contents('/proc/mounts');
        if ($text === '') return [];
        $prefix = self::agentBase() . "/.versions/$agentId/";
        $found = [];
        foreach (explode("\n", $text) as $line) {
            // /proc/mounts is space-separated with octal escapes; the target is
            // field 2. Only the prefix match matters here, and a generation id
            // never contains a space.
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 2) continue;
            $target = $parts[1];
            if (strncmp($target, $prefix, strlen($prefix)) !== 0) continue;
            $gen = substr($target, strlen($prefix));
            // Only the generation directory itself, never something mounted
            // deeper inside one.
            if ($gen === '' || strpos($gen, '/') !== false) continue;
            if (!in_array($gen, $found, true)) $found[] = $gen;
        }
        return $found;
    }

    /**
     * True when another version of this agent may be brought up beside the ones
     * already mounted. Pure given its inputs so the ceiling is unit-testable.
     */
    public static function canAddGeneration(int $mountedCount, int $max = self::MAX_CONCURRENT_GENERATIONS): bool {
        return $mountedCount < $max;
    }

    /**
     * Retrieves the unified agent registry (Default + Custom).
     */
    public static function getRegistry() {
        $defaultRegistry = self::getDefaultAgents();
        $registry = $defaultRegistry;

        if (file_exists(self::MANIFEST_FILE)) {
            LogService::log("Merging custom agents from " . self::MANIFEST_FILE, LogService::LOG_DEBUG, "AgentRegistry");
            $custom = json_decode(@file_get_contents(self::MANIFEST_FILE), true);
            if (is_array($custom) && isset($custom['agents'])) {
                $registry = array_merge($defaultRegistry, $custom['agents']);
            }
        }

        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";

        // D-329: Pre-fetch all SquashFS files to avoid repeated expensive glob calls on Flash/Network storage
        $allSqsh = glob("$persistPath/*.sqsh");
        $allSqshBasenames = array_map('basename', $allSqsh ?: []);

        foreach ($registry as $id => &$agent) {
            $bin = $agent['binary'] ?? '';
            $fallback = $agent['binary_fallback'] ?? '';
            
            // D-206: Include version in the agent data for UI rendering
            $agent['version'] = self::getInstalledVersion($id);
            $agent['channel'] = self::getChannel($id);
            $agent['pinned'] = self::getPinned($id);

            $hasVersion = !empty($agent['version']) && $agent['version'] !== '0.0.0' && $agent['version'] !== 'unknown';
            $binExists = (empty($bin) || file_exists($bin)) || (!empty($fallback) && file_exists($fallback));
            
            // D-310: Robust SquashFS discovery using the cached file list.
            // Matches both legacy (vol1, delta_<epoch>, no-seq delta_<dt>/
            // consolidated_<dt>) and canonical seq-keyed (delta_<seq10>_<dt>,
            // consolidated_<seq10>_<dt> where dt = YYYYMMDDTHHMMSSZ and seq10 =
            // the monotonic layer-identity seq) formats.
            $sqshExists = false;
            $idQuoted = preg_quote($id, '/');
            $kindAlt  = '(?:v\d+_vol\d+|vol\d+|delta_\d+|delta_\d{8}T\d{6}Z|delta_\d+_\d{8}T\d{6}Z|consolidated_\d{8}T\d{6}Z|consolidated_\d+_\d{8}T\d{6}Z)';
            foreach ($allSqshBasenames as $basename) {
                if (preg_match("/^agent_{$idQuoted}_{$kindAlt}\.sqsh$/", $basename)) {
                    $sqshExists = true;
                    break;
                }
            }
            
            // D-312/R2: 'is_installed' = binary OR sqsh OR a real version in versions.json.
            // The third arm covers passthrough-storage agents (e.g. codex-cli) where the
            // binary may be stale/absent but versions.json records the installed release.
            // Forgejo #303: that third arm must not keep a record that no stored
            // content can back. The heal runs only when the binary is missing AND
            // no layer exists AND a real version is recorded (rare), so the
            // common render costs nothing extra.
            if (!$binExists && !$sqshExists && $hasVersion
                && self::healPhantomInstallRecord($id, $binExists, $sqshExists, $persistPath)) {
                $agent['version'] = self::getInstalledVersion($id);
            }
            $agent['is_installed'] = self::computeIsInstalled($binExists, $sqshExists, $id);

            // D-326: Also consider 'installed' if a background installation is currently running
            // This prevents the 'INSTALL' button from reappearing if the user refreshes during install.
            if (!$agent['is_installed']) {
                $statusFile = "/tmp/unraid-aicliagents/install-status-{$id}";
                if (file_exists($statusFile)) {
                    $status = json_decode(@file_get_contents($statusFile), true);
                    if ($status && isset($status['progress']) && $status['progress'] > 0 && $status['progress'] < 100) {
                        $agent['is_installed'] = true;
                    }
                }
            }

            if ($id === 'terminal') $agent['is_installed'] = true;

            // Lazy-populate versions.json if missing but agent is installed.
            // Best-effort discovery — works when the agent overlay happens to
            // be mounted (e.g. a session is open for it). When unmounted,
            // returns 'unknown' and we keep the 0.0.0 sentinel; the explicit
            // backfill below (recoverMissingVersions) handles those cases.
            // Eagerly mounting every 0.0.0 agent here was too expensive —
            // smoke run hit PHP's 256 MB limit because getRegistry is called
            // on every page render.
            if ($agent['is_installed'] && (!$hasVersion || $agent['version'] === '0.0.0')) {
                $v = self::discoverVersion($id, $agent);
                // Only save if we got a real version (not 'unknown' — that means sqsh isn't mounted yet)
                if ($v && $v !== 'unknown') {
                    // Merge the discovered version with the current file under
                    // the versions lock. Do not write the snapshot captured at
                    // the start of getRegistry(), because a concurrent Beta
                    // selection must not be overwritten by this self-heal.
                    self::saveVersion($id, $v);
                    $agent['version'] = $v;
                    LogService::log("Restored version for $id: $v", LogService::LOG_INFO, "AgentRegistry");
                }
            }
        }

        return $registry;
    }

    public static function getDefaultAgents() {
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1: route through agentBase()
        // instead of reading the constant directly, even here in its own
        // defining class — see agentBase()'s docblock for why.
        $agentBase = self::agentBase();
        return [
            'gemini-cli' => [
                'id' => 'gemini-cli',
                'data_dir_env' => ['GEMINI_CLI_HOME' => 'Relocates ALL Gemini CLI state (sessions, config, credentials) away from ~/.gemini. Leave unset: a path outside the plugin home is not saved by the persistent storage of the plugin, so history and logins vanish on reboot.'],
                'name' => 'Gemini CLI',
                'description' => 'Google\'s high-performance AI agent for advanced coding and system analysis.',
                'npm_package' => '@google/gemini-cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/google-gemini.png',
                'binary' => "$agentBase/gemini-cli/node_modules/@google/gemini-cli/bundle/gemini.js",
                'resume_cmd' => "{binary} {args} --resume {chatId}",
                'resume_latest' => "{binary} {args} --resume",
                'env_prefix' => 'GEMINI',
                'changelog_url' => 'https://github.com/google-gemini/gemini-cli/releases',
                // T-12: first-run wizard auth hint — shown in step 3 checklist.
                'auth_hint' => 'Run `gemini auth login` on first launch to authenticate with your Google account (OAuth). Credentials persist in your managed home directory across reboots.',
                // No `default_envs` shipped. (GEMINI_CLI_ENABLE_AUTO_UPDATE was
                // tried 2026-05-11 but Gemini CLI doesn't yet honour it — removed.)
                // The manifest-seeding infrastructure stays wired (EnvService::
                // seedAgentDefaults from install + PLG INLINE on upgrade); add a
                // `default_envs` map to any agent entry here when a real default
                // is needed — it'll be seeded additively (never overwrites a user
                // value, never auto-removed later, user deletions honoured via the
                // seeded sidecar). Per WP #736 ENV_AND_SECRETS_TIERS.
                //
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): re-researched why
                // the env var above never worked. Gemini CLI's real, DOCUMENTED
                // self-update control is the settings.json key
                // `general.enableAutoUpdate: false` in ~/.gemini/settings.json —
                // confirmed at https://geminicli.com/docs/cli/settings/ — not an env
                // var and not a CLI flag; its own docs page states no environment
                // variable equivalent exists. Do not re-add an env var here; that
                // was already tried and doesn't work.
                //
                // Seeded through `default_settings` by AgentSettingsSeedService on
                // the same unconditional install / plugin-upgrade path that seeds
                // `default_envs` — additive, never overwrites a user value, and a
                // deliberate deletion is not resurrected (seeded sidecar).
                'default_settings' => [
                    'file'   => '.gemini/settings.json',
                    'values' => ['general.enableAutoUpdate' => false],
                ],
            ],
            'claude-code' => [
                'id' => 'claude-code',
                'name' => 'Claude Code',
                'description' => 'Anthropic\'s specialized agent for deep architectural reasoning and logic.',
                'npm_package' => '@anthropic-ai/claude-code',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/claude.ico',
                // Claude Code 2.1.x ships a native Linux binary at bin/claude.exe (the .exe
                // suffix is a package convention, not Windows-specific — the postinstall
                // script replaces it with the platform-matched binary). Older 2.0.x had
                // cli.js at the root of @anthropic-ai/claude-code; we fall back to that
                // if the native binary is absent.
                'binary' => "$agentBase/claude-code/node_modules/@anthropic-ai/claude-code/bin/claude.exe",
                'binary_fallback' => "$agentBase/claude-code/node_modules/@anthropic-ai/claude-code/cli.js",
                'resume_cmd' => "{binary} {args} --resume {chatId}",
                'resume_latest' => "{binary} {args} --resume",
                'env_prefix' => 'CLAUDE',
                'changelog_url' => 'https://github.com/anthropics/claude-code/releases',
                // T-12: first-run wizard auth hint — shown in step 3 checklist.
                'auth_hint' => 'Run /login on first launch; your Anthropic credentials are stored in your managed home directory and persist across reboots and plugin upgrades.',
                // R-A1 (CLAUDE_RELAUNCH_SURVIVAL): claude 2.1.x refuses
                // --dangerously-skip-permissions when run as root unless IS_SANDBOX=1
                // is set. The plugin runs agents as root; shipping this default means
                // claude works out-of-the-box without a manual user env step.
                // Seeded additively via EnvService::seedAgentDefaults (additive,
                // sidecar-tracked, never overwrites a user value, never auto-removed).
                // A user who already has IS_SANDBOX in their agent-env tier wins
                // (5-tier merge: default_envs → seeded into tier-4 agent-env, but any
                // higher-tier value beats it at buildEffectiveEnv time).
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): DISABLE_UPDATES blocks
                // EVERY update path — background auto-updater AND `claude update` /
                // `claude install` run manually inside the session — so an agent
                // upgrade can only happen through this plugin's Store. Confirmed via
                // the official docs: https://code.claude.com/docs/en/setup
                // ("Disable auto-updates" section) — "DISABLE_AUTOUPDATER only stops
                // the background check; claude update and claude install still work.
                // To block all update paths, including manual updates, set
                // DISABLE_UPDATES instead." We use the stronger of the two on purpose.
                'default_envs' => ['IS_SANDBOX' => '1', 'DISABLE_UPDATES' => '1'],
                // T-07: Claude Code requires extended-keys on + terminal-features xterm*:extkeys
                // for Shift+Enter to be delivered as a distinct key chord (vs. plain Enter).
                // This is agent-specific: applied as tier 1.5 (after BUILTIN, before user JSON
                // tiers) so user overrides always win. escape-time 10 gives a small guard
                // against ESC-sequence mis-parse while still being fast enough for TUI use.
                'tmux_profile' => [
                    'extended-keys'     => 'on',
                    'terminal-features' => 'xterm*:extkeys',
                    'escape-time'       => '10',
                ],
            ],
            'opencode' => [
                'id' => 'opencode',
                'data_dir_env' => ['OPENCODE_CONFIG' => 'Points OpenCode at a config file outside ~/.config/opencode. Leave unset: a path outside the plugin home is not saved by the persistent storage of the plugin.'],
                'name' => 'OpenCode',
                'description' => 'An open-source oriented agent optimized for local development workflows.',
                'npm_package' => 'opencode-ai',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/opencode.ico',
                // WP #932: opencode-ai ships bin/opencode.exe (cross-platform
                // naming — postinstall replaces with the platform-matched
                // binary, same convention as claude-code). The previous
                // `bin/opencode` path doesn't exist in the package; the agent
                // worked anyway because node_modules/.bin/opencode resolves
                // to the right file, but our binExists file_exists() check
                // was hitting the wrong raw path.
                'binary' => "$agentBase/opencode/node_modules/opencode-ai/bin/opencode.exe",
                'binary_fallback' => "$agentBase/opencode/node_modules/.bin/opencode",
                'resume_cmd' => "{binary} {args} -s {chatId}",
                'resume_latest' => "{binary} {args} --continue",
                'env_prefix' => 'OPENCODE',
                // npm package carries no repository metadata — npm versions page
                // is the verified fallback (no GitHub releases to point at).
                'changelog_url' => 'https://www.npmjs.com/package/opencode-ai?activeTab=versions',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): OpenCode's DOCUMENTED
                // knob is the config-file key `"autoupdate": false` in opencode.json
                // (https://opencode.ai/docs/config/) — this plugin has no facility to
                // write into an agent's own JSON config today, only launch-time env
                // vars/flags. OPENCODE_DISABLE_AUTOUPDATE is an UNDOCUMENTED env-var
                // workaround reported in https://github.com/anomalyco/opencode/issues/20027
                // ("`\"autoupdate\": false` setting is ignored for local config file")
                // — verified as REAL (not invented) by grepping the literal string
                // OPENCODE_DISABLE_AUTOUPDATE out of the installed opencode.exe binary
                // on this box on 2026-09-10 (bin/opencode.exe under this agent's
                // overlay). Primary-evidence verified per CLAUDE.md; flag as
                // undocumented if the vendor ever changes this.
                'default_envs' => ['OPENCODE_DISABLE_AUTOUPDATE' => 'true'],
            ],
            'kilocode' => [
                'id' => 'kilocode',
                'name' => 'Kilo Code',
                'description' => 'Ultra-fast, lightweight coding assistant for rapid prototyping.',
                'npm_package' => '@kilocode/cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/kilocode.ico',
                'binary' => "$agentBase/kilocode/node_modules/@kilocode/cli/bin/kilo",
                // WP #932 (post-test correction): kilo --help confirms BOTH
                // -c/--continue (resume last) and -s/--session <id> (per-ID
                // resume) exist. Original code's `-s {chatId}` was correct
                // for resume_cmd — keep that semantic, use the long form for
                // readability. resume_latest gains --continue (was plain
                // {binary} {args} which would have started a new session).
                'resume_cmd' => "{binary} {args} --session {chatId}",
                'resume_latest' => "{binary} {args} --continue",
                'env_prefix' => 'KILOCODE',
                'changelog_url' => 'https://github.com/Kilo-Org/kilocode/releases',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): Kilo Code CLI is an
                // opencode fork (identical `service=default ... opencode` startup log
                // line, identical command set) and carries the same env var pattern
                // under its own KILO_ prefix. No vendor doc page documents this (Kilo
                // Code's own docs at kilo.ai don't cover it); verified as REAL by
                // grepping the literal string KILO_DISABLE_AUTOUPDATE out of the real
                // bundled binary bin/.kilo (the wrapper at bin/kilo execs it) on this
                // box on 2026-09-10. Primary-evidence verified per CLAUDE.md.
                'default_envs' => ['KILO_DISABLE_AUTOUPDATE' => '1'],
            ],
            'pi-coder' => [
                'id' => 'pi-coder',
                'data_dir_env' => ['PI_CODING_AGENT_DIR' => 'Relocates ALL pi-coder state away from ~/.pi/agent. Leave unset: a path outside the plugin home is not saved by the persistent storage of the plugin, so sessions and keys vanish on reboot.'],
                'name' => 'Pi Coder',
                'description' => 'Specialized Python and Data Science agent with deep tool integration.',
                'npm_package' => '@mariozechner/pi-coding-agent',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/picoder.png',
                'binary' => "$agentBase/pi-coder/node_modules/@mariozechner/pi-coding-agent/dist/cli.js",
                // WP #932: pi-coder's --resume opens an interactive session
                // picker (blocks indefinitely in our SSH-attach flow with no
                // TTY input). Direct-ID resume is --session; continue-last is
                // --continue. Previous resume_latest hung the workspace launch.
                'resume_cmd' => "{binary} {args} --session {chatId}",
                'resume_latest' => "{binary} {args} --continue",
                'env_prefix' => 'PI_CODER',
                'changelog_url' => 'https://github.com/badlogic/pi-mono/releases',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): official docs
                // (https://pi.dev/docs/latest/settings) — "Set PI_SKIP_VERSION_CHECK=1
                // to disable the Pi version update check." We use the narrow flag
                // rather than PI_OFFLINE=1 (which also disables package-update checks
                // and install/update telemetry) to avoid touching behaviour beyond
                // the self-update surface this suppression is scoped to.
                'default_envs' => ['PI_SKIP_VERSION_CHECK' => '1'],
            ],
            'gh-copilot' => [
                'id' => 'gh-copilot',
                'name' => 'GitHub Copilot',
                'description' => 'GitHub\'s official CLI agent for natural language shell, git, and GitHub commands.',
                'npm_package' => '@github/copilot',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/githubcopilotcli.png',
                // WP #932: npm-loader.js is the real entry — it tries the
                // native SEA binary first and falls back to index.js on
                // failure. Pointing directly at index.js bypassed the native
                // path and slowed every launch.
                'binary' => "$agentBase/gh-copilot/node_modules/@github/copilot/npm-loader.js",
                'binary_fallback' => "$agentBase/gh-copilot/node_modules/@github/copilot/index.js",
                // WP #932 (post-test correction): copilot --help confirms BOTH
                // --continue (resume most recent) and --resume[=value] (per-ID
                // resume) exist — the agent IS stateful. Keep --resume={chatId}
                // for per-ID; gain --continue for resume_latest (previous plain
                // {binary} {args} would not have resumed at all).
                'resume_cmd' => "{binary} {args} --resume={chatId}",
                'resume_latest' => "{binary} {args} --continue",
                'env_prefix' => 'GH_COPILOT',
                'changelog_url' => 'https://github.com/github/copilot-cli/releases',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-24): the Copilot CLI
                // native binary runs the NEWEST package it finds in its package
                // cache (~/.cache/copilot/pkg/<platform>/<version>, also
                // $COPILOT_PKG_CACHE_HOME / $COPILOT_CACHE_HOME / ~/.copilot/pkg),
                // not the one npm installed, unless auto-update is off. Verified
                // in the 1.0.87-0 binary's own code: auto-update is enabled
                // unless argv has --no-auto-update / --prefer-version or
                // COPILOT_AUTO_UPDATE is "false". Live on .4 (2026-09-24): a
                // downgrade to 1.0.87-0 still ran 1.0.88 from a HOME that had run
                // 1.0.88 once; with COPILOT_AUTO_UPDATE=false it ran 1.0.87-0.
                // Without this, a downgrade or a pinned version never takes
                // effect for a user whose managed HOME ever ran a newer copilot.
                'default_envs' => ['COPILOT_AUTO_UPDATE' => 'false'],
            ],
            'codex-cli' => [
                'id' => 'codex-cli',
                'data_dir_env' => ['CODEX_HOME' => 'Relocates ALL Codex state (sessions, config, auth) away from ~/.codex. Leave unset: a path outside the plugin home is not saved by the persistent storage of the plugin, so history and logins vanish on reboot.'],
                'name' => 'Codex CLI',
                'description' => 'OpenAI Codex-powered agent for translating natural language to code and shell commands.',
                'npm_package' => '@openai/codex',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/codex.png',
                // @openai/codex v0.99+ split the native binary out of the main
                // package into a per-platform optionalDependency
                // (@openai/codex-linux-x64, aliased via npm:@openai/codex@<ver>-linux-x64).
                // npm installs that optional dep alongside the main package, so the
                // Rust binary now lives under node_modules/@openai/codex-linux-x64/
                // rather than node_modules/@openai/codex/vendor/.
                // The main package's bin/codex.js does require.resolve(
                // '@openai/codex-linux-x64/package.json') and derives the vendor path
                // from there — so invoking bin/codex.js (Node) works as fallback, but
                // we prefer the Rust binary for speed and to avoid the JS wrapper.
                // binary_fallback covers pre-v0.99 installs that still have the old
                // bundled-vendor layout (node_modules/@openai/codex/vendor/...) which
                // is now the wrong primary path.
                'binary' => "$agentBase/codex-cli/node_modules/@openai/codex-linux-x64/vendor/x86_64-unknown-linux-musl/bin/codex",
                'binary_fallback' => "$agentBase/codex-cli/node_modules/@openai/codex/bin/codex.js",
                // Unraid-specific: Unraid's / is a ramfs/rootfs mount where
                // pivot_root(2) always returns EINVAL, so codex's bundled bwrap
                // sandbox cannot start. We disable the OS sandbox via the -c CLI
                // override (takes precedence over ~/.codex/config.toml) and keep
                // HITL approval (on-request) in place — exactly the posture every
                // other agent in this plugin already runs under. These flags are a
                // plugin default, NOT user-editable workspace args.
                // plugin_args is appended LAST so codex's last-`-c`-wins
                // makes the plugin sandbox_mode override any user workspace arg.
                // See docs/specs/CODEX_SANDBOX_MODE_UNRAID.md
                //
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): check_for_update_on_startup
                // is a documented config.toml key — "Check for Codex updates on
                // startup (set to false only when updates are centrally managed)" —
                // confirmed at https://developers.openai.com/codex/config-reference
                // (redirects to https://learn.chatgpt.com/docs/config-file/config-reference).
                // Applied as a `-c` override alongside the sandbox flags above so it
                // always wins over any user workspace arg, same rationale.
                //
                // TERMINAL_MOBILE_COPY_PASTE.md (2026-09-29): --no-alt-screen keeps
                // Codex on the normal screen (inline mode). Codex 0.153 drew inline
                // by default; 0.158.0 starts in the full-screen (alternate) mode,
                // where tmux keeps no history, so a phone swipe could not scroll
                // back and Latest had nothing to return from. The flag exists in
                // every Codex this plugin installs (checked on 0.153.4 and 0.158.0:
                // an unknown flag exits 2, this one does not).
                'plugin_args' => '--no-alt-screen -c sandbox_mode=danger-full-access -c approval_policy=on-request -c check_for_update_on_startup=false',
                // Codex 0.144.1 grammar is `codex [OPTIONS] resume [SESSION_ID]`.
                // Keep user and plugin global options before the subcommand;
                // a plain Codex invocation starts a new conversation.
                'resume_cmd' => "{binary} {args} {plugin_args} resume {chatId}",
                'resume_latest' => "{binary} {args} {plugin_args} resume --last",
                'env_prefix' => 'CODEX',
                'changelog_url' => 'https://github.com/openai/codex/releases',
                // T-12: codex-cli supports two auth paths: interactive login via
                // `codex login` (browser OAuth), or set the OPENAI_API_KEY env var
                // in the Secrets panel. The env-var path requires no interactive step.
                // On Unraid, codex runs without an OS sandbox (kernel ramfs root blocks
                // bwrap); shell commands are gated by HITL approval (on-request).
                'auth_hint' => 'Either run `codex login` on first launch (browser OAuth), or add your OPENAI_API_KEY in the Secrets panel — the env-var path requires no interactive authentication. Note: on Unraid, codex runs without an OS sandbox (kernel ramfs root blocks bwrap); commands are gated by approval.',
            ],
            'factory-cli' => [
                'id' => 'factory-cli',
                'name' => 'Factory CLI',
                'description' => 'The Droid agent from Factory for automated software engineering workflows.',
                'npm_package' => '@factory/cli',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/factory.png',
                'binary' => "$agentBase/factory-cli/node_modules/@factory/cli/bin/droid",
                'resume_cmd' => "{binary} {args}",
                'resume_latest' => "{binary} {args}",
                'env_prefix' => 'FACTORY',
                // @factory/cli has no public release history — npm versions page.
                'changelog_url' => 'https://www.npmjs.com/package/@factory/cli?activeTab=versions',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): official docs
                // (https://docs.factory.ai/reference/cli-reference) — "The npm
                // distribution has auto-updates disabled at build time and does not
                // require this variable [FACTORY_DROID_AUTO_UPDATE_ENABLED]." This
                // agent installs via npm_package above, so droid's self-updater is
                // already inert; the env var is set anyway as a defensive belt-and-
                // suspenders measure in case a future change moves this agent off the
                // npm distribution.
                'default_envs' => ['FACTORY_DROID_AUTO_UPDATE_ENABLED' => 'false'],
            ],
            'nanocoder' => [
                'id' => 'nanocoder',
                'data_dir_env' => ['NANOCODER_DATA_DIR' => 'Relocates ALL Nanocoder data away from ~/.local/share/nanocoder. Leave unset: a path outside the plugin home is not saved by the persistent storage of the plugin.'],
                'name' => 'NanoCoder',
                'description' => 'Lightweight, ultra-portable coding agent for small-scale tasks.',
                'npm_package' => '@nanocollective/nanocoder',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/nanocoder.png',
                'binary' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
                'resume_cmd' => "{binary} {args}",
                'resume_latest' => "{binary} {args}",
                'env_prefix' => 'NANOCODER',
                'changelog_url' => 'https://github.com/Nano-Collective/nanocoder/releases',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): no self-update
                // mechanism to suppress — Nanocoder ships no update-notifier/
                // auto-updater dependency at all. Verified by fetching
                // @nanocollective/nanocoder's published package.json from the npm
                // registry (registry.npmjs.org) on 2026-09-10 and inspecting its
                // full `dependencies` list: no update-notifier, simple-update-
                // notifier, or any auto-update package is present. See
                // AgentRegistry::SELF_UPDATE_EXEMPT.
            ],
            'goose' => [
                'id' => 'goose',
                'name' => 'Goose',
                'description' => 'Block\'s open-source on-machine AI agent — native Rust binary with a session-based workflow.',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/goose.png',
                'source' => [
                    'type' => 'github_release',
                    'repo' => 'block/goose',
                    'asset_pattern' => 'goose-{arch}-unknown-linux-gnu.tar.bz2',
                    'binary_in_archive' => 'goose',
                    'executable' => 'goose',
                    'version_probe' => '{binary} --version',
                ],
                'binary' => "$agentBase/goose/bin/goose",
                // WP #932: `session --name {chatId}` CREATES a new session
                // named {chatId} instead of resuming an existing one. Correct
                // direct-ID resume is `session --resume -n <name>`. The
                // resume_latest form was already correct (`--resume` without
                // a name forks the most recent).
                'resume_cmd' => "{binary} {args} session --resume -n {chatId}",
                'resume_latest' => "{binary} {args} session --resume",
                'env_prefix' => 'GOOSE',
                'changelog_url' => 'https://github.com/block/goose/releases',
                // Three-field envs: Provider + Model + one dynamic API key whose env
                // name is resolved at save time based on the selected provider
                // ({GOOSE_PROVIDER}_API_KEY → ANTHROPIC_API_KEY, OPENAI_API_KEY, etc.).
                // The save handler (save_vault) does the substitution — see
                // resolveDynamicEnv() in UtilityHandler.
                // Renamed from `secrets` 2026-05-11 (WP #736 ENV_AND_SECRETS_TIERS) for
                // parallelism with `default_envs` — both are agent-manifest entries; the
                // av2_secrets_schema() reader accepts either name (back-compat for
                // hand-written agents.json).
                'default_secrets' => [
                    ['env' => 'GOOSE_PROVIDER', 'label' => 'Provider', 'type' => 'select',
                     'options' => ['anthropic', 'openai', 'google', 'groq', 'ollama']],
                    ['env' => 'GOOSE_MODEL', 'label' => 'Model', 'type' => 'text',
                     'placeholder' => 'claude-sonnet-4-5'],
                    ['env' => '{GOOSE_PROVIDER}_API_KEY', 'label' => 'API Key', 'type' => 'password',
                     'help' => 'Stored as ANTHROPIC_API_KEY / OPENAI_API_KEY / etc. — resolved from the Provider selection above.'],
                ],
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): no self-update
                // mechanism to suppress for the CLI binary this agent installs
                // (source.type github_release, the goose-*-unknown-linux-gnu.tar.bz2
                // asset). Confirmed at https://goose-docs.ai/docs/guides/updating-goose/
                // — updating requires the user to explicitly run `goose update`;
                // there is no automatic startup check or background download for the
                // CLI. (A GOOSE_DISABLE_AUTO_DOWNLOAD env var and a
                // "disableAutoDownload" setting DO exist, from PR block/goose#9872 —
                // but those gate the separate Electron DESKTOP app's auto-updater
                // [autoUpdater.ts/main.ts/preload.ts], which this plugin does not
                // install or run.) See AgentRegistry::SELF_UPDATE_EXEMPT.
            ],
            'qwen-code' => [
                'id' => 'qwen-code',
                'name' => 'Qwen Code',
                'description' => 'Alibaba\'s Qwen-powered coding agent; Gemini-CLI-style interface tuned for Qwen3 Coder models.',
                'npm_package' => '@qwen-code/qwen-code',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/qwen-code.png',
                // package.json declares "bin":{"qwen":"cli.js"}; node_modules/.bin/qwen is
                // the resolved entry point (and has a node shebang for native exec).
                'binary' => "$agentBase/qwen-code/node_modules/.bin/qwen",
                'binary_fallback' => "$agentBase/qwen-code/node_modules/@qwen-code/qwen-code/cli.js",
                'resume_cmd' => "{binary} {args} --resume {chatId}",
                'resume_latest' => "{binary} {args} --resume",
                'env_prefix' => 'QWEN',
                'changelog_url' => 'https://github.com/QwenLM/qwen-code/releases',
                // WP #936: surface the primary API key for Alibaba's DashScope
                // (qwen-code's default provider). Without this in default_secrets
                // the user has to discover the env var name themselves; with it
                // they get a labelled password field on the Store card.
                'default_secrets' => [
                    ['env' => 'DASHSCOPE_API_KEY', 'label' => 'DashScope API Key', 'type' => 'password',
                     'help' => 'Required for Alibaba\'s official Qwen API. Alternative providers (Ollama, vLLM) can be configured via the general env panel.'],
                ],
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): Qwen Code is a
                // gemini-cli fork and shares its settings architecture. Its
                // documented self-update control is also a settings.json key —
                // `general.enableAutoUpdate: false` in ~/.qwen/settings.json
                // (legacy `disableAutoUpdate`/`disableUpdateNag` keys were folded
                // into it) — confirmed at
                // https://github.com/QwenLM/qwen-code/blob/main/docs/users/configuration/settings.md.
                // No env var or CLI flag equivalent is documented, so this is
                // seeded through `default_settings` by AgentSettingsSeedService,
                // exactly like gemini-cli above.
                'default_settings' => [
                    'file'   => '.qwen/settings.json',
                    'values' => ['general.enableAutoUpdate' => false],
                ],
            ],
            'antigravity-cli' => [
                'id' => 'antigravity-cli',
                'name' => 'Antigravity CLI',
                'description' => 'Google\'s agent-first CLI — the successor to Gemini CLI, sharing the Antigravity 2.0 agent engine. Multi-step reasoning, multi-file edits, persistent conversation history.',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/antigravity.ico',
                // WP #963: agy is a single static Go binary. The vendor install
                // script (curl_install source) honours a captive $HOME — with
                // CurlInstallSource's HOME=<agentDir>/home it lands the binary
                // at <agentDir>/home/.local/bin/agy. No source.executable is set,
                // so CurlInstallSource::stage() returns this `binary` verbatim.
                'source' => [
                    'type' => 'curl_install',
                    'script_url' => 'https://antigravity.google/cli/install.sh',
                    'version_probe' => '{binary} --version',
                    // CURL_INSTALL_VERSION_PIN_AND_TIMEOUT.md: ~200 MB download;
                    // the script has no version input (manifest is latest-only).
                    'timeout_s' => 900,
                    // WP #963: Antigravity ships via a self-updater, not a
                    // release history — its manifest serves only the current
                    // {version,url,sha512}. CurlInstallSource probes manifest_url
                    // for the latest installable version (Store-card badge +
                    // single-entry dropdown). No downgrade/pin — there are no
                    // archived builds to install. Unraid is always x86_64, so
                    // the linux_amd64 manifest is hard-referenced.
                    'manifest_url' => 'https://antigravity-cli-auto-updater-974169037036.us-central1.run.app/manifests/linux_amd64.json',
                    // Shared user state (#270). Sources: SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 4.
                    'captive_state' => ['home/.gemini/antigravity-cli'],
                ],
                'binary' => "$agentBase/antigravity-cli/home/.local/bin/agy",
                // Resume flags per `agy --help`: --conversation <id> resumes a
                // specific conversation; --continue (-c) resumes the most recent.
                'resume_cmd' => "{binary} {args} --conversation {chatId}",
                'resume_latest' => "{binary} {args} --continue",
                'env_prefix' => 'ANTIGRAVITY',
                // Antigravity publishes no GitHub releases/tags; CHANGELOG.md in
                // the repo is the version history. Surfaced as a Store-card link.
                'changelog_url' => 'https://github.com/google-antigravity/antigravity-cli/blob/main/CHANGELOG.md',
                // No default_secrets — auth is interactive Google OAuth (the CLI
                // prints an authorization URL and accepts a pasted code), not an
                // API-key env var.
                // T-12: first-run wizard auth hint — shown in step 3 checklist.
                'auth_hint' => 'On first launch, `agy` prints a Google authorization URL — open it in a browser, approve access, and paste the code back into the terminal. Credentials persist in your managed home directory.',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): AGY_CLI_DISABLE_AUTO_UPDATE=true
                // disables agy's own self-updater. Not on antigravity.google's own
                // docs pages we could reach, but corroborated by multiple independent
                // third-party CLI references and verified as REAL by grepping the
                // literal string AGY_CLI_DISABLE_AUTO_UPDATE out of the installed agy
                // binary on this box on 2026-09-10. Primary-evidence verified per
                // CLAUDE.md.
                'default_envs' => ['AGY_CLI_DISABLE_AUTO_UPDATE' => 'true'],
            ],
            'grok-build' => [
                'id' => 'grok-build',
                'name' => 'Grok Build',
                'description' => 'xAI\'s official terminal coding agent with planning, subagents, MCP, skills and resumable sessions.',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/grok.svg',
                'source' => [
                    'type' => 'curl_install',
                    'script_url' => 'https://x.ai/cli/install.sh',
                    'version_probe' => '{binary} --version',
                    'manifest_url' => 'https://x.ai/cli/stable',
                    'manifest_format' => 'plain',
                    'env' => ['GROK_CHANNEL' => 'stable'],
                    // CURL_INSTALL_VERSION_PIN_AND_TIMEOUT.md: install.sh takes the
                    // version as its first positional argument (`bash -s 0.1.42`,
                    // TARGET="$1"), so a pinned/channel-resolved target is honoured.
                    'version_args' => ['{version}'],
                    'timeout_s' => 900,
                    // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 4 — 2026-09-23 (#270).
                    // https://x.ai/cli/install.sh reads ~/.grok/auth.json (the
                    // `grok login` credential), writes the [cli] block of
                    // ~/.grok/config.toml, and writes managed_config.toml and
                    // requirements.toml for a deployment key. Sessions live in
                    // ~/.grok/sessions (https://docs.x.ai/build/cli/headless-scripting).
                    'captive_state' => [
                        'home/.grok/auth.json',
                        'home/.grok/config.toml',
                        'home/.grok/managed_config.toml',
                        'home/.grok/requirements.toml',
                        'home/.grok/sessions',
                    ],
                ],
                'binary' => "$agentBase/grok-build/home/.grok/bin/grok",
                // Grok otherwise self-updates the plugin-owned binary. Keep the
                // plugin Store as the sole version authority on every launch.
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): confirmed at
                // https://docs.x.ai/build/cli/headless-scripting — "pass
                // --no-auto-update ... to skip background update checks." A
                // persistent alternative also exists (`auto_update = false` under
                // [cli] in ~/.grok/config.toml) but the CLI flag already covers every
                // launch this plugin makes, so no config-file write is needed.
                'plugin_args' => '--no-auto-update',
                'resume_cmd' => '{binary} {args} {plugin_args} --resume {chatId}',
                'resume_latest' => '{binary} {args} {plugin_args} --continue',
                'env_prefix' => 'GROK',
                'changelog_url' => 'https://x.ai/cli/changelog',
                'auth_hint' => 'On first workspace launch, follow Grok Build\'s in-agent account-linking flow. As an optional alternative, add XAI_API_KEY in Secrets.',
                'default_envs' => ['GROK_TELEMETRY_ENABLED' => 'false'],
                'default_secrets' => [
                    ['env' => 'XAI_API_KEY', 'label' => 'xAI API Key', 'type' => 'password',
                     'help' => 'Optional alternative to Grok device authentication.'],
                ],
            ],
            'kimi-code' => [
                'id' => 'kimi-code',
                'name' => 'Kimi Code',
                'description' => 'Moonshot AI\'s current terminal coding agent with subagents, MCP, skills and persistent sessions.',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/kimi-code.svg',
                'source' => [
                    'type' => 'curl_install',
                    'script_url' => 'https://code.kimi.com/kimi-code/install.sh',
                    'version_probe' => '{binary} --version',
                    'manifest_url' => 'https://code.kimi.com/kimi-code/latest',
                    'manifest_format' => 'plain',
                    'env' => ['KIMI_NO_MODIFY_PATH' => '1'],
                    // CURL_INSTALL_VERSION_PIN_AND_TIMEOUT.md: install.sh honours
                    // KIMI_VERSION (else it resolves ITS OWN latest — on 2026-09-06
                    // that fetched 0.41.0 for a 0.40.1 upgrade). The binary is
                    // ~183 MB from a Singapore CDN; the old fixed 300 s budget
                    // killed the download mid-way.
                    'version_env' => 'KIMI_VERSION',
                    'timeout_s' => 900,
                    // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 4 — 2026-09-23 (#270).
                    // The install folder and the data folder are the SAME
                    // ~/.kimi-code by default (install.sh: KIMI_INSTALL_DIR and
                    // KIMI_CODE_HOME), and install.sh writes the region marker
                    // there. The user-state entries below are the ones listed at
                    // https://www.kimi.com/code/docs/en/kimi-code-cli/configuration/data-locations.html
                    // (bin/, logs/ and updates/ are payload or scratch).
                    'captive_state' => [
                        'home/.kimi-code/region',
                        'home/.kimi-code/config.toml',
                        'home/.kimi-code/tui.toml',
                        'home/.kimi-code/mcp.json',
                        'home/.kimi-code/AGENTS.md',
                        'home/.kimi-code/credentials',
                        'home/.kimi-code/sessions',
                        'home/.kimi-code/session_index.jsonl',
                        'home/.kimi-code/user-history',
                        'home/.kimi-code/skills',
                        'home/.kimi-code/plugins',
                    ],
                ],
                'binary' => "$agentBase/kimi-code/home/.kimi-code/bin/kimi",
                'resume_cmd' => '{binary} {args} --session {chatId}',
                'resume_latest' => '{binary} {args} --continue',
                'env_prefix' => 'KIMI_CODE',
                'changelog_url' => 'https://github.com/MoonshotAI/kimi-code/releases',
                'auth_hint' => 'Kimi Code may already recognise your account. If it asks for authentication, use the login option inside the running agent workspace.',
                // AGENT_SELF_UPDATE_SUPPRESSION.md (2026-09-10): this is the exact
                // agent that prompted this suppression sweep — on 2026-09-10 its
                // in-TUI self-update prompt was accepted live and downloaded 172 MB
                // straight into the plugin's flash-backed overlay upper layer,
                // bypassing the plugin's own version tracking and upgrade machinery.
                // KIMI_CODE_NO_AUTO_UPDATE=1 "Fully disable[s] the update preflight:
                // no check, background install, or prompt" — confirmed at
                // https://www.kimi.com/code/docs/en/kimi-code-cli/configuration/env-vars.html
                // and verified as REAL by grepping the literal string
                // KIMI_CODE_NO_AUTO_UPDATE (plus its legacy alias
                // KIMI_CLI_NO_AUTO_UPDATE) out of the installed kimi binary on this
                // box on 2026-09-10.
                'default_envs' => ['KIMI_DISABLE_TELEMETRY' => '1', 'KIMI_CODE_NO_AUTO_UPDATE' => '1'],
            ],

        ];
    }

    public static function getVersions() {
        $path = self::versionsPath();
        if (file_exists($path)) {
            $versions = json_decode(file_get_contents($path), true);
            return is_array($versions) ? $versions : [];
        }
        return [];
    }

    /**
     * Get the installed version string for an agent.
     * Handles both old format ("1.2.3") and new format ({"installed": "1.2.3", "channel": "latest"}).
     */
    public static function getInstalledVersion(string $agentId): string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if ($entry === null) return '0.0.0';
        if (is_string($entry)) return $entry;
        return $entry['installed'] ?? '0.0.0';
    }

    /**
     * Get the selected user-facing channel (default: "stable").
     *
     * Older plugin builds stored "latest" for the control labelled Stable.
     * Treat that legacy value as stable so an existing selection cannot keep
     * following a newer npm dist-tag after this defect is fixed.
     */
    public static function getChannel(string $agentId): string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if (!is_array($entry)) return 'stable';
        return self::normalizeChannel((string)($entry['channel'] ?? 'stable'));
    }

    /** Normalize persisted/UI channel values to the supported semantic set. */
    public static function normalizeChannel(string $channel): string {
        $channel = strtolower(trim($channel));
        if ($channel === 'latest' || $channel === '') return 'stable';
        return in_array($channel, ['stable', 'beta', 'pinned'], true) ? $channel : 'stable';
    }

    /**
     * Get the pinned version for an agent (null if not pinned).
     */
    public static function getPinned(string $agentId): ?string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if (!is_array($entry)) return null;
        return $entry['pinned'] ?? null;
    }

    public static function saveVersions($versions) {
        self::withVersionsLock(function () use ($versions): void {
            if (!AtomicWriteService::writeJson(self::versionsPath(), $versions)) {
                LogService::log("Unable to save versions file", LogService::LOG_WARN, "AgentRegistry");
            }
        });
    }

    /**
     * Save installed version, preserving channel/pinned fields if they exist.
     * Optional $probedMtime (unix timestamp) is stored as 'probed_mtime' to
     * support mtime-throttled self-heal (maybeRefreshVersion).
     */
    public static function saveVersion($agentId, $version, ?int $probedMtime = null) {
        self::mutateVersions(function (array &$versions) use ($agentId, $version, $probedMtime): void {
            $existing = $versions[$agentId] ?? null;

            if (is_array($existing)) {
                // Preserve channel/pinned, update installed
                $existing['installed'] = $version;
                if ($probedMtime !== null) {
                    $existing['probed_mtime'] = $probedMtime;
                }
                $versions[$agentId] = $existing;
            } else {
                // Migrate from old string format to new object format
                $entry = [
                    'installed' => $version,
                    'channel' => 'stable',
                    'pinned' => null,
                ];
                if ($probedMtime !== null) {
                    $entry['probed_mtime'] = $probedMtime;
                }
                $versions[$agentId] = $entry;
            }
        });
    }

    /**
     * Cheap pre-gate for the mtime-throttled version self-heal.
     *
     * Returns the binary's current mtime when a probe IS needed (the binary
     * changed since the last probe, or no real version is recorded), and null
     * when it is not (binary missing/unreadable, or unchanged since the last
     * probe). It stats one file and reads versions.json — it never mounts an
     * overlay and never spawns a process, so a caller can use it to decide
     * whether the expensive part (mount + probe) is worth doing at all.
     */
    public static function versionProbeMtime(string $agentId, string $binPath): ?int {
        if (empty($binPath) || !file_exists($binPath)) {
            return null;
        }

        clearstatcache(true, $binPath);
        $mtime = @filemtime($binPath);
        if ($mtime === false) {
            return null;
        }

        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;

        $recorded = is_array($entry) ? ($entry['installed'] ?? null) : (is_string($entry) ? $entry : null);
        $sentinels = ['', '0.0.0', 'unknown', 'installed', null];
        $hasRealVersion = !in_array($recorded, $sentinels, true) && preg_match('/^\d+\.\d+\.\d+/', (string)$recorded);

        $storedMtime = is_array($entry) ? ($entry['probed_mtime'] ?? 0) : 0;

        if ($hasRealVersion && $mtime <= $storedMtime) {
            // Binary unchanged — no probe needed.
            return null;
        }

        return (int)$mtime;
    }

    /**
     * Mtime-throttled version self-heal. Checks whether the binary at $binPath
     * has changed since the last probe (via stored 'probed_mtime') and, if so,
     * invokes $discoverFn to re-discover the version and persist the result.
     *
     * The injectable $discoverFn(string $agentId): ?string callback makes the
     * method unit-testable without spawning real processes.
     *
     * Throttle logic:
     *  - If $binPath does not exist: skip (nothing to probe).
     *  - Compute current filemtime($binPath).
     *  - If no real version recorded OR current mtime > stored probed_mtime:
     *      invoke $discoverFn; persist version (and mtime) on success;
     *      persist mtime even on failure to avoid repeated re-probing.
     *  - Otherwise: skip (binary unchanged, recorded version still valid).
     */
    public static function maybeRefreshVersion(string $agentId, string $binPath, callable $discoverFn): void {
        $mtime = self::versionProbeMtime($agentId, $binPath);
        if ($mtime === null) {
            // Binary missing, unreadable, or unchanged since the last probe.
            return;
        }

        $sentinels = ['', '0.0.0', 'unknown', 'installed', null];

        // Binary is new or changed (or version was missing): probe.
        $v = $discoverFn($agentId);

        if ($v && !in_array($v, $sentinels, true) && preg_match('/^\d+\.\d+\.\d+/', (string)$v)) {
            self::saveVersion($agentId, $v, $mtime);
            LogService::log("maybeRefreshVersion: $agentId -> $v (mtime=$mtime)", LogService::LOG_INFO, "AgentRegistry");
        } else {
            // Discovery failed — still record the mtime so we don't hammer the binary.
            self::mutateVersions(function (array &$versions) use ($agentId, $mtime): void {
                $existing = $versions[$agentId] ?? null;
                if (is_array($existing)) {
                    $existing['probed_mtime'] = $mtime;
                    $versions[$agentId] = $existing;
                } else {
                    $installed = is_string($existing) ? $existing : '0.0.0';
                    $versions[$agentId] = ['installed' => $installed, 'channel' => 'stable', 'pinned' => null, 'probed_mtime' => $mtime];
                }
            });
            LogService::log("maybeRefreshVersion: $agentId discovery yielded '$v' (mtime recorded=$mtime)", LogService::LOG_WARN, "AgentRegistry");
        }
    }

    /**
     * Set the channel (and optionally pinned version) for an agent.
     */
    public static function setChannel(string $agentId, string $channel, ?string $pinned = null): bool {
        $channel = self::normalizeChannel($channel);
        return self::mutateVersions(function (array &$versions) use ($agentId, $channel, $pinned): void {
            $existing = $versions[$agentId] ?? null;
            $installed = is_string($existing) ? $existing : ($existing['installed'] ?? '0.0.0');

            $probedMtime = is_array($existing) ? ($existing['probed_mtime'] ?? null) : null;
            $entry = [
                'installed' => $installed,
                'channel' => $channel,
                'pinned' => $pinned,
            ];
            if ($probedMtime !== null) {
                $entry['probed_mtime'] = $probedMtime;
            }
            $versions[$agentId] = $entry;
        });
    }

    public static function removeVersion($agentId) {
        self::mutateVersions(function (array &$versions) use ($agentId): void {
            unset($versions[$agentId]);
        });
    }

    /**
     * R2: Returns true iff versions.json records a real (non-sentinel) installed version.
     * False for: null, missing entry, '' (empty string), '0.0.0', 'unknown' (discoverVersion
     * failure sentinel), 'installed' (background-install placeholder — real version not yet
     * written by the post-install discovery pass).
     */
    public static function hasRealInstalledVersion(string $id): bool {
        $v = self::getInstalledVersion($id);
        return $v !== '' && $v !== '0.0.0' && $v !== 'unknown' && $v !== 'installed';
    }

    /**
     * R2: Shared is_installed predicate — OR of all three installation signals.
     * Replaces the inline `$binExists || $sqshExists` at every callsite so that
     * passthrough-storage agents (no .sqsh, binary may be stale) can still
     * surface as installed when versions.json carries a real version.
     */
    public static function computeIsInstalled(bool $binExists, bool $sqshExists, string $id): bool {
        return $binExists || $sqshExists || self::hasRealInstalledVersion($id);
    }

    /** Where op_mount keeps an agent's writable layer when the upper is in zram. */
    const ZRAM_UPPER_BASE = '/tmp/unraid-aicliagents/zram_upper';

    /**
     * Forgejo #303 (docs/specs/AGENT_VERSION_DRIFT_SELF_HEAL.md, 2026-09-23):
     * true when the storage engine holds ANY content for this agent, so a
     * mount can show something. It is the PHP mirror of what op_mount can
     * assemble, and it is deliberately broad (fail safe):
     *
     *  1. A SquashFS layer. The glob is the engine's own `_entity_has_layers`
     *     glob (`agent_<id>_*.sqsh`), not the stricter registry regex.
     *  2. An un-baked writable layer (the zram upper or the disk upper) that
     *     is not empty. An install writes here first.
     *  3. A plain directory under `passthrough/agents/<id>` that is not empty,
     *     under every policy. Since #304 (2026-09-23) `effective_backend` keeps
     *     a never-converted plain directory bound under the "layering" policy
     *     too, so it can always supply the binary. $policy is kept in the
     *     signature for callers and logs; it no longer changes the answer.
     *
     * Pure filesystem reads (no mount, no process). $policy is the RAW value
     * from the cfg file ('' when the key is absent — the engine then decides
     * by device, so the plain directory counts).
     */
    public static function agentHasStoredContent(string $id, string $persistPath, string $policy, string $zramBase = self::ZRAM_UPPER_BASE): bool {
        if (!preg_match(self::AGENT_ID_RE, $id)) return true;   // unknown shape: never heal it
        $persist = rtrim($persistPath, '/');
        if (glob($persist . '/agent_' . $id . '_*.sqsh') ?: []) return true;
        $nonEmpty = static function (string $dir): bool {
            if (!is_dir($dir)) return false;
            $entries = @scandir($dir);
            if ($entries === false) return true;   // unreadable: assume content
            return count(array_diff($entries, ['.', '..'])) > 0;
        };
        if ($nonEmpty(rtrim($zramBase, '/') . "/agents/$id/upper")) return true;
        if ($nonEmpty("$persist/_upper/agents/$id")) return true;
        if ($nonEmpty("$persist/passthrough/agents/$id")) return true; // #304: bound under every policy
        return false;
    }

    /**
     * Forgejo #303: the pure decision. Clear the recorded version only when
     * ALL of these are true: the registry binary is missing, no layer exists,
     * a real version is recorded, the storage engine has no content for the
     * agent, and nothing is in progress for it ($busy).
     */
    public static function shouldClearPhantomInstall(bool $binExists, bool $sqshExists, bool $hasRealVersion, bool $hasStoredContent, bool $busy): bool {
        return !$binExists && !$sqshExists && $hasRealVersion && !$hasStoredContent && !$busy;
    }

    /** The RAW storage_backend_mode from the cfg file ('' when absent), as bash `_rp_read_cfg` reads it. */
    public static function rawStorageBackendPolicy(): string {
        $path = ConfigService::CONFIG_PATH;
        if (!is_file($path)) return '';
        $cfg = @parse_ini_file($path);
        return is_array($cfg) ? (string)($cfg['storage_backend_mode'] ?? '') : '';
    }

    /**
     * Forgejo #303: true when the persist path can be trusted to show its
     * content. A path under /mnt/<name>/ needs /mnt/<name> in the mount table:
     * an unmounted pool leaves an empty directory on the root filesystem, and
     * an empty glob there must never read as "nothing is stored" (op_mount
     * defers the same case with target_not_mounted).
     */
    public static function persistRootMounted(string $persistPath, ?string $mounts = null): bool {
        if (!preg_match('#^/mnt/([^/]+)(/|$)#', $persistPath, $m)) return true;
        $mounts = $mounts ?? (@file_get_contents('/proc/mounts') ?: '');
        return StorageMountService::mountTableHasTarget($mounts, '/mnt/' . $m[1]);
    }

    /**
     * Forgejo #303: true when a heal must NOT run now for this agent. Fail
     * safe: any doubt (a check that cannot run) counts as busy.
     */
    private static function phantomHealBlocked(string $id, string $persistPath): bool {
        if (class_exists(StorageMountService::class)) {
            if (StorageMountService::isMigrationInProgress()) return true;
            if (!StorageMountService::isPathAvailable($persistPath)) return true;
            if (!self::persistRootMounted($persistPath)) return true;
        } elseif (!is_dir($persistPath)) {
            return true;
        }
        if (class_exists(HaltService::class) && HaltService::isHalted('agent', $id)) return true;   // the halt card owns recovery
        if (class_exists(LayerManifestService::class)) {
            $entity = LayerManifestService::getEntity("agent/$id");
            if (is_array($entity) && !empty($entity['layers'])) return true;   // expected layers are gone: total_loss, not ours
        }
        if (!class_exists(\AICliAgents\Handlers\AgentHandler::class)) {
            // A page render does not load the handler. It has no side effects
            // on include, so load it: it owns the one "install in progress" test.
            $handler = __DIR__ . '/../handlers/AgentHandler.php';
            if (is_file($handler)) require_once $handler;
        }
        if (!class_exists(\AICliAgents\Handlers\AgentHandler::class)) return true;   // cannot see an install: do not guess
        return \AICliAgents\Handlers\AgentHandler::isInstallInProgress($id);
    }

    /**
     * Forgejo #303: clear a recorded version that no stored content can back.
     *
     * The state: versions.json claims a version, the registry binary is
     * missing, and the storage engine has nothing to mount (no layer, an empty
     * writable layer, no usable plain directory). The Store card then showed
     * the agent as installed and every launch printed "[Agent Binary Missing]".
     * After this heal the card shows the Install button.
     *
     * Only "installed" and "probed_mtime" are removed. The channel and the pin
     * stay, so a reinstall keeps the user's choice. The write re-checks the
     * recorded value under the versions lock, so a concurrent install that
     * records a new version wins. Returns true when the record was cleared.
     */
    public static function healPhantomInstallRecord(string $id, bool $binExists, bool $sqshExists, string $persistPath, string $zramBase = self::ZRAM_UPPER_BASE): bool {
        if ($id === 'terminal' || $binExists || $sqshExists) return false;   // cheap exits first
        $recorded = self::getInstalledVersion($id);
        if (!self::hasRealInstalledVersion($id)) return false;
        $hasContent = self::agentHasStoredContent($id, $persistPath, self::rawStorageBackendPolicy(), $zramBase);
        if ($hasContent) return false;
        $busy = self::phantomHealBlocked($id, $persistPath);
        if (!self::shouldClearPhantomInstall(false, false, true, false, $busy)) return false;

        $cleared = false;
        self::mutateVersions(function (array &$versions) use ($id, $recorded, &$cleared): void {
            $entry = $versions[$id] ?? null;
            $now = is_array($entry) ? ($entry['installed'] ?? null) : (is_string($entry) ? $entry : null);
            if ($now !== $recorded) return;   // changed under us: leave it
            if (is_array($entry)) {
                unset($entry['installed'], $entry['probed_mtime']);
                $versions[$id] = $entry;
            } else {
                unset($versions[$id]);
            }
            $cleared = true;
        });
        if ($cleared) {
            LogService::log("Install record cleared for $id: versions.json recorded $recorded, but the binary is missing and storage holds no layer, no writable-layer data and no usable plain directory. The Store card now offers Install.", LogService::LOG_WARN, "AgentRegistry");
            if (class_exists(LifecycleLogService::class)) {
                LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, 'agent_registry', 'agent_install_record_cleared', ['agent' => $id, 'recorded' => $recorded]);
            }
        }
        return $cleared;
    }

    /**
     * R4: Remove the versions.json entry for $id and return true.
     * Called by the uninstall path so the UI no longer shows the agent as installed
     * after uninstall (important for passthrough-storage agents whose binary path
     * may continue to exist on the filesystem after uninstall).
     */
    public static function clearVersion(string $id): bool {
        self::removeVersion($id);
        return true;
    }

    /**
     * Checks for updates per source type (NPM dist-tags, GitHub releases, custom index URLs).
     * Each source's checkUpdates() returns null when updates are not discoverable for that
     * agent (e.g. curl_install with no repo), which surfaces as N/A in the Store tab.
     */
    public static function checkUpdates() {
        // Re-probe what is REALLY installed before comparing against what is
        // available. Without this the recorded version only ever refreshes
        // during a plugin upgrade (the PLG INLINE block is the sole other
        // caller), so an agent that updated itself through its own CLI keeps
        // reporting its old version — and the update badge compares the
        // available version against a number that is no longer on disk.
        // recoverMissingVersions() is mtime-throttled: an agent whose binary
        // has not changed since the last probe costs one stat.
        try {
            self::recoverMissingVersions();
        } catch (\Throwable $e) {
            LogService::log("checkUpdates: version self-heal failed: " . $e->getMessage(), LogService::LOG_WARN, "AgentRegistry");
        }

        $registry = self::getRegistry();
        $updates = [];

        foreach ($registry as $id => $agent) {
            if ($id === 'terminal') continue;
            $source = SourceResolver::resolve($agent);
            if ($source === null) continue;

            $channel = self::getChannel($id);
            $result = $source->checkUpdates($id, $agent, $channel);
            if (is_array($result)) $updates[$id] = $result;
        }
        return ['updates' => $updates];
    }

    /**
     * Delegates to the agent's source implementation for version discovery.
     * Accepts either (id, agent-entry) for the new path, or legacy (id, bin, fallback)
     * for call sites that haven't been migrated — we rebuild the entry from the default
     * registry in that case. Returns 'unknown' when the source can't determine a version.
     */
    public static function discoverVersion($id, $agentOrBin = null, $fallback = '') {
        if (is_array($agentOrBin)) {
            $agent = $agentOrBin;
        } else {
            $registry = self::getDefaultAgents();
            $agent = $registry[$id] ?? null;
            if (!$agent) return null;
            if (!empty($agentOrBin)) $agent['binary'] = $agentOrBin;
            if (!empty($fallback))   $agent['binary_fallback'] = $fallback;
        }

        $source = SourceResolver::resolve($agent);
        if ($source === null) return 'unknown';
        $v = $source->discoverVersion($id, $agent);
        return $v ?: 'unknown';
    }

    /**
     * Mount-time version self-heal. For every installed agent:
     *  - If the version is missing/sentinel AND the binary exists: probe the
     *    binary and record the result (legacy recovery behaviour).
     *  - If a real version IS recorded: use mtime-throttled probing via
     *    maybeRefreshVersion so a manual CLI self-update is picked up without
     *    probing on every call (binary unchanged → skip; binary newer → probe).
     *
     * Called explicitly from PLG INLINE post-install and from a CLI entry
     * point — NOT from getRegistry, which is called on every page render and
     * cannot afford the per-call mount cost (memory + I/O).
     *
     * Returns: ['recovered' => N, 'skipped' => N, 'failed' => [agentIds...]]
     */
    public static function recoverMissingVersions(): array {
        $registry = self::getDefaultAgents();
        $versions = self::getVersions();
        $recovered = 0;
        $skipped = 0;
        $failed = [];

        $sentinels = ['', '0.0.0', 'unknown', 'installed', null];

        // Build the same sqsh-existence map getRegistry uses so we recognise
        // installed-but-unmounted agents (the whole point of this method).
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        $allSqsh = glob("$persistPath/*.sqsh") ?: [];
        $allSqshBasenames = array_map('basename', $allSqsh);
        $kindAlt = '(?:v\d+_vol\d+|vol\d+|delta_\d+|delta_\d{8}T\d{6}Z|delta_\d+_\d{8}T\d{6}Z|consolidated_\d{8}T\d{6}Z|consolidated_\d+_\d{8}T\d{6}Z)';

        foreach ($registry as $id => $agent) {
            if ($id === 'terminal') continue;

            // Determine "is this agent installed?" via the SAME logic as
            // getRegistry's is_installed gate: binary path exists OR a
            // matching .sqsh layer exists in storage. Without the sqsh
            // arm we miss every lazy-mounted agent — which is the whole
            // population this method needs to fix.
            $bin = $agent['binary'] ?? '';
            $fallback = $agent['binary_fallback'] ?? '';
            $binExists = (empty($bin) || file_exists($bin)) || (!empty($fallback) && file_exists($fallback));
            $sqshExists = false;
            $idQuoted = preg_quote($id, '/');
            foreach ($allSqshBasenames as $bn) {
                if (preg_match("/^agent_{$idQuoted}_{$kindAlt}\.sqsh$/", $bn)) {
                    $sqshExists = true;
                    break;
                }
            }
            // Forgejo #303: a recorded version that no stored content backs is
            // drift too — the drift toward "nothing is installed". Clear it
            // here as well, so "Check updates" never compares against it.
            if (self::healPhantomInstallRecord($id, $binExists, $sqshExists, $persistPath)) {
                $skipped++;
                continue;
            }
            if (!self::computeIsInstalled($binExists, $sqshExists, $id)) {
                // Not installed by any signal — nothing to recover.
                $skipped++;
                continue;
            }

            $current = $versions[$id]['installed'] ?? null;
            $hasSentinel = in_array($current, $sentinels, true) || !preg_match('/^\d+\.\d+\.\d+/', (string)$current);

            // Resolve the effective binary path (primary preferred, then fallback).
            $effectiveBin = ($bin && file_exists($bin)) ? $bin
                : (($fallback && file_exists($fallback)) ? $fallback : $bin);

            if ($effectiveBin && file_exists($effectiveBin)) {
                // Mount the overlay so node_modules/<pkg>/package.json (or the
                // source-specific version probe) can read its data. Only do it
                // when a probe is actually needed: the mount is the expensive
                // half, and this method now also runs on the user-initiated
                // "Check updates" path, where mounting every installed agent
                // unconditionally would be a large and pointless cost.
                //
                // An agent whose overlay is not mounted at all does not reach
                // this branch — its binary is invisible, so the enclosing
                // file_exists() sends it down the "no binary found" path below.
                if (self::versionProbeMtime($id, $effectiveBin) !== null) {
                    if (class_exists('\AICliAgents\Services\FileStorage')) {
                        @\AICliAgents\Services\FileStorage::ensureReady("agent/$id");   // Epic #1310: facade intent
                    }
                }

                $agentRef = $agent; // capture for closure
                $discoverFn = function(string $agentId) use ($agentRef): ?string {
                    return self::discoverVersion($agentId, $agentRef);
                };

                $versionsBefore = self::getVersions();
                $installedBefore = $versionsBefore[$id]['installed'] ?? null;

                self::maybeRefreshVersion($id, $effectiveBin, $discoverFn);

                $versionsAfter = self::getVersions();
                $installedAfter = $versionsAfter[$id]['installed'] ?? null;

                if ($installedAfter !== $installedBefore &&
                    $installedAfter && !in_array($installedAfter, $sentinels, true) &&
                    preg_match('/^\d+\.\d+\.\d+/', (string)$installedAfter)) {
                    $recovered++;
                } elseif ($hasSentinel && in_array($installedAfter, $sentinels, true)) {
                    $failed[] = $id;
                    LogService::log("recoverMissingVersions: $id failed (discovery returned '$installedAfter' after mount attempt)", LogService::LOG_WARN, "AgentRegistry");
                } else {
                    $skipped++;
                }
            } elseif ($hasSentinel) {
                // Binary not present and version missing: can't probe.
                $failed[] = $id;
                LogService::log("recoverMissingVersions: $id failed (no binary found)", LogService::LOG_WARN, "AgentRegistry");
            } else {
                $skipped++;
            }
        }

        return ['recovered' => $recovered, 'skipped' => $skipped, 'failed' => $failed];
    }
}
