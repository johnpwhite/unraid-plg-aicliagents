<?php
/**
 * <module_context>
 *     <name>AgentCommandEnvService</name>
 *     <description>Makes sure the commands that an agent runs keep the AICLI_* variables, so that
 *     $AICLI_RELAY_COMMAND works inside the agent (docs/specs/AGENT_SPAWN_WORKSPACE.md R21, issue #688).
 *     An agent builds the environment of its command tool from its own settings. Codex does it from the table
 *     [shell_environment_policy] in ~/.codex/config.toml. Without `inherit = "all"` the variables are gone, and
 *     the agent falls back to the slower Relay MCP tool on every step.</description>
 *     <dependencies>AgentRegistry (built-in entries), CodexProjector (the fenced policy block), LogService</dependencies>
 *     <constraints>Data driven: the registry key `command_env` of the built-in agent entry names the rule; an agent
 *     with no such key is left alone. It runs from the shared launcher (aicli-shell.sh, every launch) and from the
 *     spawn tool. A policy that the user wrote outside the plugin fence always wins (the Config Hub rule of #688):
 *     the service reports it and writes nothing. It never throws and never stops a launch.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Hub\CodexProjector;
use AICliAgents\Services\Hub\HubProjector;

class AgentCommandEnvService
{
    public const MODE_CODEX_POLICY = 'codex_policy';

    /** Result states. */
    public const NOT_APPLICABLE = 'not_applicable';
    public const PRESENT = 'present';
    public const ADDED = 'added';
    public const USER_POLICY_OK = 'user_policy_ok';
    public const USER_POLICY_BLOCKS = 'user_policy_blocks';
    public const FAILED = 'failed';

    /** @return array<string,mixed>|null the `command_env` rule of a built-in agent */
    public static function ruleFor(string $agentId): ?array
    {
        $rule = AgentRegistry::getDefaultAgents()[$agentId]['command_env'] ?? null;
        return is_array($rule) ? $rule : null;
    }

    /** True when the user's own policy text sets `inherit = "all"`, which keeps every AICLI_* variable. */
    public static function userPolicyKeepsVariables(string $text): bool
    {
        return preg_match('/^[ \t]*inherit[ \t]*=[ \t]*"all"/m', $text) === 1;
    }

    /**
     * @param string|null $homeOverride The overlay home that holds .codex/ (the launcher passes its own home; a test passes a folder)
     * @return array{state:string,reason?:string}
     */
    public static function ensure(string $agentId, ?string $homeOverride = null): array
    {
        try {
            $rule = self::ruleFor($agentId);
            if ($rule === null || ($rule['mode'] ?? '') !== self::MODE_CODEX_POLICY) return ['state' => self::NOT_APPLICABLE];

            if ($homeOverride !== null && $homeOverride !== '') {
                $home = rtrim($homeOverride, '/');
            } else {
                $resolved = HubProjector::resolveHome();
                if (empty($resolved['ok'])) return ['state' => self::FAILED, 'reason' => 'home_unavailable'];
                $home = rtrim((string)$resolved['home'], '/');
            }
            $file = $home . '/.codex/config.toml';
            $raw = is_file($file) ? (string)@file_get_contents($file) : '';

            // The plugin's own block is there: Config Hub or an earlier launch wrote it.
            if (CodexProjector::extractFence($raw, CodexProjector::POLICY_OPEN, CodexProjector::POLICY_CLOSE) !== null) {
                return ['state' => self::PRESENT];
            }
            // The user wrote a policy: it wins. Report whether it keeps the variables.
            if (CodexProjector::definesShellEnvironmentPolicy($raw)) {
                if (self::userPolicyKeepsVariables($raw)) return ['state' => self::USER_POLICY_OK];
                LogService::log("The Codex policy in $file does not keep the AICLI_* variables, so the Relay command may be missing in the agent. The plugin leaves the policy as the user wrote it.", LogService::LOG_WARN, 'AgentCommandEnvService');
                return ['state' => self::USER_POLICY_BLOCKS];
            }

            $projector = new CodexProjector();
            $block = $projector->desired([])[CodexProjector::POLICY_KEY] ?? null;
            if (!is_string($block)) return ['state' => self::FAILED, 'reason' => 'no_policy_block'];
            @mkdir(dirname($file), 0700, true);
            if (!$projector->write($file, [CodexProjector::POLICY_KEY => $block], [])) {
                return ['state' => self::FAILED, 'reason' => 'write_failed'];
            }
            LogService::log("Added the Codex command environment policy to $file, so that AICLI_RELAY_COMMAND reaches the commands of the agent.", LogService::LOG_INFO, 'AgentCommandEnvService');
            return ['state' => self::ADDED];
        } catch (\Throwable $e) {
            return ['state' => self::FAILED, 'reason' => 'error'];
        }
    }
}
