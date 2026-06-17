<?php
/**
 * <module_context>
 *     <name>AgentRegistry</name>
 *     <description>Management of the AI agent manifest and installation logic.</description>
 *     <dependencies>LogService, ConfigService</dependencies>
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

        $versions = self::getVersions();
        $versionsChanged = false;

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
            // Matches both legacy (vol1, delta_<epoch>) and canonical
            // (delta_<dt>, consolidated_<dt> where dt = YYYYMMDDTHHMMSSZ) formats.
            $sqshExists = false;
            $idQuoted = preg_quote($id, '/');
            $kindAlt  = '(?:v\d+_vol\d+|vol\d+|delta_\d+|delta_\d{8}T\d{6}Z|delta_\d+_\d{8}T\d{6}Z|consolidated_\d{8}T\d{6}Z|consolidated_\d+_\d{8}T\d{6}Z)';
            foreach ($allSqshBasenames as $basename) {
                if (preg_match("/^agent_{$idQuoted}_{$kindAlt}\.sqsh$/", $basename)) {
                    $sqshExists = true;
                    break;
                }
            }
            
            // D-312: Relaxed 'is_installed' logic. If binaries exist, it is installed.
            // A missing version in versions.json should not block the 'Installed' state.
            $agent['is_installed'] = ($binExists || $sqshExists);
            
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
                    $existing = $versions[$id] ?? null;
                    if (is_array($existing)) {
                        $existing['installed'] = $v;
                        $versions[$id] = $existing;
                    } else {
                        $versions[$id] = ['installed' => $v, 'channel' => 'latest', 'pinned' => null];
                    }
                    $agent['version'] = $v;
                    $versionsChanged = true;
                    LogService::log("Restored version for $id: $v", LogService::LOG_INFO, "AgentRegistry");
                }
            }
        }

        if ($versionsChanged) {
            self::saveVersions($versions);
        }

        return $registry;
    }

    public static function getDefaultAgents() {
        $agentBase = self::AGENT_BASE;
        return [
            'gemini-cli' => [
                'id' => 'gemini-cli',
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
            ],
            'pi-coder' => [
                'id' => 'pi-coder',
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
            ],
            'codex-cli' => [
                'id' => 'codex-cli',
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
                'binary' => "$agentBase/codex-cli/node_modules/@openai/codex-linux-x64/vendor/x86_64-unknown-linux-musl/codex/codex",
                'binary_fallback' => "$agentBase/codex-cli/node_modules/@openai/codex/vendor/x86_64-unknown-linux-musl/codex/codex",
                'resume_cmd' => "{binary} {args}",
                'resume_latest' => "{binary} {args}",
                'env_prefix' => 'CODEX',
                'changelog_url' => 'https://github.com/openai/codex/releases',
                // T-12: codex-cli supports two auth paths: interactive login via
                // `codex login` (browser OAuth), or set the OPENAI_API_KEY env var
                // in the Secrets panel. The env-var path requires no interactive step.
                'auth_hint' => 'Either run `codex login` on first launch (browser OAuth), or add your OPENAI_API_KEY in the Secrets panel — the env-var path requires no interactive authentication.',
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
            ],
            'nanocoder' => [
                'id' => 'nanocoder',
                'name' => 'NanoCoder',
                'description' => 'Lightweight, ultra-portable coding agent for small-scale tasks.',
                'npm_package' => '@nanocollective/nanocoder',
                'icon_url' => '/plugins/unraid-aicliagents/src/assets/icons/nanocoder.png',
                'binary' => "$agentBase/nanocoder/node_modules/.bin/nanocoder",
                'resume_cmd' => "{binary} {args}",
                'resume_latest' => "{binary} {args}",
                'env_prefix' => 'NANOCODER',
                'changelog_url' => 'https://github.com/Nano-Collective/nanocoder/releases',
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
                    // WP #963: Antigravity ships via a self-updater, not a
                    // release history — its manifest serves only the current
                    // {version,url,sha512}. CurlInstallSource probes manifest_url
                    // for the latest installable version (Store-card badge +
                    // single-entry dropdown). No downgrade/pin — there are no
                    // archived builds to install. Unraid is always x86_64, so
                    // the linux_amd64 manifest is hard-referenced.
                    'manifest_url' => 'https://antigravity-cli-auto-updater-974169037036.us-central1.run.app/manifests/linux_amd64.json',
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
            ],

        ];
    }

    public static function getVersions() {
        if (file_exists(self::VERSIONS_FILE)) {
            return json_decode(file_get_contents(self::VERSIONS_FILE), true) ?: [];
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
     * Get the selected channel for an agent (default: "latest").
     */
    public static function getChannel(string $agentId): string {
        $versions = self::getVersions();
        $entry = $versions[$agentId] ?? null;
        if (!is_array($entry)) return 'latest';
        return $entry['channel'] ?? 'latest';
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
        file_put_contents(self::VERSIONS_FILE, json_encode($versions, JSON_PRETTY_PRINT));
    }

    /**
     * Save installed version, preserving channel/pinned fields if they exist.
     */
    public static function saveVersion($agentId, $version) {
        $versions = self::getVersions();
        $existing = $versions[$agentId] ?? null;

        if (is_array($existing)) {
            // Preserve channel/pinned, update installed
            $existing['installed'] = $version;
            $versions[$agentId] = $existing;
        } else {
            // Migrate from old string format to new object format
            $versions[$agentId] = [
                'installed' => $version,
                'channel' => 'latest',
                'pinned' => null,
            ];
        }
        self::saveVersions($versions);
    }

    /**
     * Set the channel (and optionally pinned version) for an agent.
     */
    public static function setChannel(string $agentId, string $channel, ?string $pinned = null): void {
        $versions = self::getVersions();
        $existing = $versions[$agentId] ?? null;
        $installed = is_string($existing) ? $existing : ($existing['installed'] ?? '0.0.0');

        $versions[$agentId] = [
            'installed' => $installed,
            'channel' => $channel,
            'pinned' => $pinned,
        ];
        self::saveVersions($versions);
    }

    public static function removeVersion($agentId) {
        $versions = self::getVersions();
        if (isset($versions[$agentId])) {
            unset($versions[$agentId]);
            self::saveVersions($versions);
        }
    }

    /**
     * Checks for updates per source type (NPM dist-tags, GitHub releases, custom index URLs).
     * Each source's checkUpdates() returns null when updates are not discoverable for that
     * agent (e.g. curl_install with no repo), which surfaces as N/A in the Store tab.
     */
    public static function checkUpdates() {
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
     * One-shot recovery for installed agents whose versions.json entry is
     * the 0.0.0 sentinel — typically because they were installed by a prior
     * plugin version that didn't write the version eagerly, AND the agent
     * overlay isn't currently mounted (so getRegistry's lazy discovery can't
     * read package.json). For each such agent: ensure the overlay is mounted,
     * call discoverVersion, write the result. Idempotent; cheap on healthy
     * registries (skips agents that already have a real semver).
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
            $current = $versions[$id]['installed'] ?? null;
            if (!in_array($current, $sentinels, true) && preg_match('/^\d+\.\d+\.\d+/', (string)$current)) {
                $skipped++;
                continue;
            }
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
            if (!$binExists && !$sqshExists) {
                // Not installed — nothing to recover.
                $skipped++;
                continue;
            }
            // Mount the overlay so node_modules/<pkg>/package.json (or the
            // source-specific version probe) can read its data.
            if (class_exists('\AICliAgents\Services\FileStorage')) {
                @\AICliAgents\Services\FileStorage::ensureReady("agent/$id");   // Epic #1310: facade intent
            }
            $v = self::discoverVersion($id, $agent);
            if ($v && $v !== 'unknown' && preg_match('/^\d+\.\d+\.\d+/', (string)$v)) {
                $existing = $versions[$id] ?? null;
                if (is_array($existing)) {
                    $existing['installed'] = $v;
                    $versions[$id] = $existing;
                } else {
                    $versions[$id] = ['installed' => $v, 'channel' => 'latest', 'pinned' => null];
                }
                $recovered++;
                LogService::log("recoverMissingVersions: $id -> $v", LogService::LOG_INFO, "AgentRegistry");
            } else {
                $failed[] = $id;
                LogService::log("recoverMissingVersions: $id failed (discovery returned '$v' after mount attempt)", LogService::LOG_WARN, "AgentRegistry");
            }
        }

        if ($recovered > 0) {
            self::saveVersions($versions);
        }
        return ['recovered' => $recovered, 'skipped' => $skipped, 'failed' => $failed];
    }
}
