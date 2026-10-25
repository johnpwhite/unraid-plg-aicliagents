<?php
/**
 * <module_context>
 *     <name>AgentLaunchProfile</name>
 *     <description>Turns a model, an effort and an "unattended" request into the real launch flags of one
 *     agent, from the `launch_profile` key of the agent entry in AgentRegistry::getDefaultAgents().
 *     The caller of the spawn tool never writes raw flags. Spec: docs/specs/AGENT_SPAWN_WORKSPACE.md R4.</description>
 *     <dependencies>AgentRegistry, ArgsService</dependencies>
 *     <constraints>Pure and static. The profile is read from the BUILT-IN agent entries only, never from a
 *     custom agent manifest: an operator file must not decide which flags lower an agent's own checks. A
 *     missing profile key means "not supported" and the answer is a refusal sentence, never a guess. The
 *     built string must pass ArgsService::validateArgs().</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AgentLaunchProfile
{
    /** A model name: letters, digits and . _ : / - only, 1 to 80 characters. */
    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/-]{1,80}$/D';

    /** Prompt modes a profile may declare. `flag:--name` is checked by promptModeValid(). */
    public const PROMPT_NONE = 'none';
    public const PROMPT_POSITIONAL = 'positional';

    /** Admin tools that are tagged "read" but write the caller's own cursor or subscription. A read-only run denies them too. */
    // aicli_get_events moves the caller's read position by default (ack is true).
    public const ADMIN_READ_BUT_WRITES = ['aicli_ack_events', 'aicli_subscribe_events', 'aicli_get_events'];

    /** Options of the agent that take a list of values. Each one is written with `=`, so it never takes the prompt after it. */
    public const VARIADIC_OPTIONS = ['--tools', '--allowedTools', '--disallowedTools', '--mcp-config', '--add-dir', '--plugin-dir'];

    /**
     * The comma list for `--disallowedTools` of a read-only run: the built-in tools in `deny_tools`, and, with
     * `deny_plugin_tools`, every plugin tool of the tiers change and destructive (except the report tool
     * aicli_finish_task), the read tools that write a cursor, and the changing Relay tools
     * (docs/specs/AGENT_RUN_ENTRY_POINT.md R13). The allow list (`tools`) and the MCP file are the main fence;
     * this list is the second one.
     *
     * @param array<string,mixed> $readonly The `readonly` key of a launch profile.
     */
    public static function denyList(array $readonly): string
    {
        $names = [];
        foreach ((array)($readonly['deny_tools'] ?? []) as $tool) {
            if (is_string($tool) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $tool) === 1) $names[] = $tool;
        }
        if (!empty($readonly['deny_plugin_tools'])) {
            foreach (AdminMcpTools::TIERS as $tool => $tier) {
                if ((in_array($tier, ['change', 'destructive'], true) && $tool !== 'aicli_finish_task') || in_array($tool, self::ADMIN_READ_BUT_WRITES, true)) {
                    $names[] = 'mcp__aicli-admin__' . $tool;
                }
            }
            foreach (RelayMcpTools::changingTools() as $tool) $names[] = 'mcp__aicli-relay__' . $tool;
        }
        return implode(',', array_values(array_unique($names)));
    }

    /**
     * The read-only flags need a recent agent: an older one stops with "unknown option". When the profile names a
     * minimum version and the installed version is known and older, this gives one sentence that names the flags.
     * An unknown version gives null (the start goes on).
     *
     * @param array<string,mixed>|null $profile
     */
    public static function versionError(string $agentId, string $installed, ?array $profile = null): ?string
    {
        $profile = $profile ?? self::profileFor($agentId);
        $min = (string)($profile['readonly']['min_version'] ?? '');
        // Only the numeric prefix counts: "2.1.302 (Claude Code)" and "2.1.302-beta" are version 2.1.302.
        if ($min === '' || preg_match('/^(\d+(?:\.\d+)*)/', trim($installed), $m) !== 1 || $m[1] === '0.0.0') return null;
        $installed = $m[1];
        if (version_compare($installed, $min, '>=')) return null;
        return "A read-only run needs the agent '$agentId' in version $min or newer: it uses the options --tools, --strict-mcp-config, --setting-sources and --restricted, and the installed version $installed may stop with \"unknown option\". Upgrade the agent, or ask for may-change (a person must approve it).";
    }

    /**
     * The content of the MCP file for a read-only run: only the two plugin servers, started the way the Config Hub
     * starts them. With `--strict-mcp-config` the agent reads no other MCP server.
     *
     * @return array<string,mixed>
     */
    public static function readOnlyMcpConfig(): array
    {
        return ['mcpServers' => [
            'aicli-admin' => ['command' => '/usr/bin/php', 'args' => [AdminMcpTools::mcpScriptPath()]],
            'aicli-relay' => ['command' => '/usr/bin/php', 'args' => [AgentRelayService::mcpScriptPath()]],
        ]];
    }

    /**
     * The launch profile of one built-in agent, or null when the agent has none.
     *
     * @return array<string,mixed>|null
     */
    public static function profileFor(string $agentId): ?array
    {
        $defaults = AgentRegistry::getDefaultAgents();
        $profile = $defaults[$agentId]['launch_profile'] ?? null;
        return is_array($profile) ? $profile : null;
    }

    /** R20: how the agent pre-trusts a folder: 'codex_toml', or '' when the agent has no such way. */
    public static function trustMode(string $agentId, ?array $profile = null): string
    {
        $profile = $profile ?? self::profileFor($agentId);
        $mode = (string)($profile['trust']['mode'] ?? '');
        return $mode === 'codex_toml' ? $mode : '';
    }

    /** True for 'none', 'positional' and 'flag:--name' (lower-case letters and dashes after the two dashes). */
    public static function promptModeValid(string $mode): bool
    {
        return $mode === self::PROMPT_NONE
            || $mode === self::PROMPT_POSITIONAL
            || preg_match('/^flag:--[a-z][a-z-]{0,30}$/D', $mode) === 1;
    }

    /**
     * The first-prompt mode of an agent: what its profile declares, or 'none' when it declares nothing.
     * With 'none' the spawn tool types the first line into the pane instead (R22).
     */
    public static function promptMode(string $agentId, ?array $profile = null): string
    {
        $profile = $profile ?? self::profileFor($agentId);
        $mode = (string)($profile['prompt']['mode'] ?? self::PROMPT_NONE);
        return self::promptModeValid($mode) ? $mode : self::PROMPT_NONE;
    }

    /**
     * Build the launch flags for one helper workspace.
     *
     * @param string|null $model  A model name, or null for the agent's own default.
     * @param string|null $effort One of the profile's effort values, or null.
     * @param bool        $unattended True when the caller asks for the profile's unattended flags. The
     *                    caller has already checked the setting and the worktree rule; this method only
     *                    refuses an agent that has no such flags.
     * @param array<string,mixed>|null $profile Test seam: a profile to use instead of the registry's.
     * @param bool        $readOnly True for a script run in read-only mode (docs/specs/AGENT_RUN_ENTRY_POINT.md R13).
     *                    The profile key `readonly.flags` makes the agent unable to change files. An agent without
     *                    that key is refused: the plugin never runs a read-only request without a way to enforce it.
     * @return array{args:string}|array{error:string}
     */
    public static function flags(string $agentId, ?string $model, ?string $effort, bool $unattended, ?array $profile = null, bool $readOnly = false, string $mcpConfigPath = "", string $dataDir = ""): array
    {
        $profile = $profile ?? self::profileFor($agentId);
        $parts = [];

        if ($model !== null && $model !== '') {
            $rule = $profile['model']['flag'] ?? null;
            if (!is_string($rule) || strpos($rule, '{model}') === false) {
                return ['error' => "The agent '$agentId' has no model flag in its launch profile, so a model cannot be chosen for it. Leave out the model."];
            }
            if (preg_match(self::MODEL_PATTERN, $model) !== 1) {
                return ['error' => 'The model name may use letters, digits and the characters . _ : / - only, up to 80 characters.'];
            }
            $parts[] = str_replace('{model}', $model, $rule);
        }

        if ($effort !== null && $effort !== '') {
            $rule = $profile['effort']['flag'] ?? null;
            if (!is_string($rule) || strpos($rule, '{effort}') === false) {
                return ['error' => "The agent '$agentId' has no effort flag in its launch profile, so an effort cannot be chosen for it. Leave out the effort."];
            }
            $allowed = is_array($profile['effort']['values'] ?? null) ? $profile['effort']['values'] : [];
            if (!in_array($effort, $allowed, true)) {
                return ['error' => "The effort '$effort' is not one of: " . implode(', ', $allowed) . '.'];
            }
            $parts[] = str_replace('{effort}', $effort, $rule);
        }

        if ($unattended) {
            $flags = $profile['unattended']['flags'] ?? null;
            if (!is_string($flags) || trim($flags) === '') {
                return ['error' => "The agent '$agentId' has no unattended flags in its launch profile. Leave out the unattended request."];
            }
            $parts[] = trim($flags);
        }

        if ($readOnly) {
            $flags = $profile['readonly']['flags'] ?? null;
            if (!is_string($flags) || trim($flags) === '') {
                return ['error' => "The agent '$agentId' has no read-only mode in its launch profile, so it cannot run read-only. Use another agent, or ask for may-change (a person must approve it)."];
            }
            if ($unattended) {
                return ['error' => 'A read-only run cannot also be unattended.'];
            }
            // An allow list of the built-in tools, then only the two plugin MCP servers, then the deny list. Every option
            // that takes a list is written with `=`, and the plain flags (plan mode) come LAST, so the first prompt that
            // the shell appends is a positional word and never a value of an option (finding A of the second review).
            $ro = $profile['readonly'];
            if (is_string($ro['tools'] ?? null) && preg_match('/^[A-Za-z][A-Za-z0-9_,]*$/D', $ro['tools']) === 1) {
                $parts[] = '--tools=' . $ro['tools'];
            }
            if (!empty($ro['mcp_servers'])) {
                // A real file is required (finding B2): no empty path and no made-up fallback.
                if (preg_match('#^/[A-Za-z0-9/._-]+$#D', $mcpConfigPath) !== 1 || strpos($mcpConfigPath, '..') !== false) {
                    return ['error' => 'A read-only run needs a safe path for its MCP file.'];
                }
                $parts[] = '--strict-mcp-config';
                $parts[] = '--mcp-config=' . $mcpConfigPath;
            }
            // The folder of the run holds the settings files of a person (hooks, statusLine, apiKeyHelper run commands
            // as root). Only the user's own settings are read, and the restricted mode ignores the files of the folder,
            // removes the tools that run code and keeps the file tools inside the working folders (finding B1).
            // Claude Code 2.1.302 has both options.
            if (is_string($ro['setting_sources'] ?? null) && preg_match('/^[a-z,]+$/D', $ro['setting_sources']) === 1) {
                $parts[] = '--setting-sources=' . $ro['setting_sources'];
            }
            if (!empty($ro['restricted'])) {
                $parts[] = '--restricted';
                // The data file of the run sits outside the working folder, in a folder of its own for this run.
                if ($dataDir !== '') {
                    if (preg_match('#^/[A-Za-z0-9/._-]+$#D', $dataDir) !== 1 || strpos($dataDir, '..') !== false) {
                        return ['error' => 'A read-only run needs a safe path for its data folder.'];
                    }
                    $parts[] = '--add-dir=' . $dataDir;
                }
            }
            $deny = self::denyList($ro);
            if ($deny !== '') $parts[] = '--disallowedTools=' . $deny;
            $parts[] = trim($flags);
        }

        $args = implode(' ', $parts);
        $bad = ArgsService::validateArgs($args);
        if ($bad !== []) {
            return ['error' => 'The launch flags hold characters that are not allowed: ' . implode(', ', $bad) . '.'];
        }
        return ['args' => $args];
    }
}
