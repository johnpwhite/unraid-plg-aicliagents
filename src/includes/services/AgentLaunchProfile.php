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
     * @return array{args:string}|array{error:string}
     */
    public static function flags(string $agentId, ?string $model, ?string $effort, bool $unattended, ?array $profile = null): array
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

        $args = implode(' ', $parts);
        $bad = ArgsService::validateArgs($args);
        if ($bad !== []) {
            return ['error' => 'The launch flags hold characters that are not allowed: ' . implode(', ', $bad) . '.'];
        }
        return ['args' => $args];
    }
}
