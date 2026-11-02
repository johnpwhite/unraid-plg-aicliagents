<?php
/**
 * <module_context>
 *     <name>EnvHandler</name>
 *     <description>AJAX handler for env vars + secrets — agent-tier + workspace-tier × general + secret classes. A save never restarts a session (#463): this handler just persists and says whether anything changed; the page offers a restart.</description>
 *     <dependencies>EnvService, SecretService, ValidationService</dependencies>
 *     <constraints>Static methods only. All inputs validated. Secret values NEVER round-trip back through GET responses — only has_value flags.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\EnvService;
use AICliAgents\Services\SecretService;
use AICliAgents\Services\ValidationService;
use AICliAgents\Services\AgentRegistry;

class EnvHandler {

    public static function actions(): array {
        return [
            'get_agent_envs',
            'save_agent_envs',
            'get_workspace_envs',
            'save_workspace_envs',
            'get_agent_secrets',
            'save_agent_secrets',
            'get_workspace_secrets',
            'save_workspace_secrets',
            'get_effective_envs',
        ];
    }

    public static function handle($action, $id): ?array {
        switch ($action) {
            case 'get_agent_envs':         return self::getAgentEnvs();
            case 'save_agent_envs':        return self::saveAgentEnvs();
            case 'get_workspace_envs':     return self::getWorkspaceEnvs();
            case 'save_workspace_envs':    return self::saveWorkspaceEnvs();
            case 'get_agent_secrets':      return self::getAgentSecrets();
            case 'save_agent_secrets':     return self::saveAgentSecrets();
            case 'get_workspace_secrets':  return self::getWorkspaceSecrets();
            case 'save_workspace_secrets': return self::saveWorkspaceSecrets();
            case 'get_effective_envs':     return self::getEffectiveEnvs();
            default:                       return null;
        }
    }

    // ---------- General — agent ----------

    private static function getAgentEnvs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        return ['status' => 'ok', 'envs' => EnvService::getAgentEnvs($agentId)];
    }

    private static function saveAgentEnvs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        $payload = self::decodeMapPayload();
        [$clean, $rejected] = EnvService::validateMap($payload);
        if (!empty($rejected)) { // #666 R-154: save nothing, so a refused rename keeps the old value
            return ['status' => 'error', 'message' => self::rejectionMsg($rejected)];
        }

        // #890: the changed flag is taken from the map read inside the env lock.
        $before = [];
        $ok = EnvService::updateAgentEnvs($agentId, static function (array $cur) use ($clean, &$before) {
            $before = $cur;
            return $clean;
        });
        return $ok
            ? self::withNotice(['status' => 'ok', 'count' => count($clean), 'rejected' => $rejected, 'changed' => !self::sameMap($before, $clean)])
            : ['status' => 'error', 'message' => EnvService::lastUpdateMessage() ?? 'Write failed'];
    }

    // ---------- General — workspace ----------

    private static function getWorkspaceEnvs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = (string)($_REQUEST['path'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if ($path === '') return ['status' => 'error', 'message' => 'Missing path'];

        return [
            'status'        => 'ok',
            'envs'          => EnvService::getWorkspaceEnvs($path, $agentId), // delta-only
            'agentDefaults' => EnvService::getAgentEnvs($agentId),            // for inherit/diff display in UI
        ];
    }

    private static function saveWorkspaceEnvs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = (string)($_REQUEST['path'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if ($path === '') return ['status' => 'error', 'message' => 'Missing path'];

        $payload = self::decodeMapPayload();
        [$clean, $rejected] = EnvService::validateMap($payload);
        if (!empty($rejected)) { // #666 R-154: save nothing, so a refused rename keeps the old value
            return ['status' => 'error', 'message' => self::rejectionMsg($rejected)];
        }

        $ok = EnvService::updateWorkspaceEnvs($path, $agentId, static fn(array $cur) => $clean);
        return $ok
            ? self::withNotice(['status' => 'ok', 'count' => count($clean), 'rejected' => $rejected])
            : ['status' => 'error', 'message' => EnvService::lastUpdateMessage() ?? 'Write failed'];
    }

    // ---------- Secret — agent (free-form sub-section) ----------

    private static function getAgentSecrets(): array {
        // Return masked view (has_value bool, never values). UI filters out
        // schema-managed keys client-side based on the agent's default_secrets.
        return [
            'status'        => 'ok',
            'secrets'       => SecretService::getAgentSecretsForUI(),
            'freeform_keys' => SecretService::getFreeformKeys(),
        ];
    }

    /**
     * Save the free-form agent-secrets sub-section. POSTed map represents the
     * intended state of *free-form* secrets only — schema-managed keys
     * (declared via any agent's default_secrets) are left untouched. The
     * `secrets_freeform_keys.json` sidecar tracks which keys are free-form so
     * future saves don't accidentally clobber schema values.
     */
    private const MASKED_PLACEHOLDER = '••••••••';

    /** #636/#890: a save that worked but set a damaged file aside carries a notice for the person. */
    private static function withNotice(array $reply): array {
        $n = SecretService::lastUpdateNotice() ?? EnvService::lastUpdateNotice();
        if ($n !== null) $reply['notice'] = $n;
        return $reply;
    }

    private static function saveAgentSecrets(): array {
        $payload = self::decodeMapPayload();
        $schemaKeys = self::collectAllSchemaSecretKeys();
        $result = null;   // set by the locked step; an error array stops the save
        $clean = [];
        $current = [];
        $next = [];
        $freeformFailed = false;
        $ok = false;
        $busy = false;
        // #890: the whole read-change-write runs under the secret lock, so a second save
        // at the same time cannot read the old vault and write it back over this one.
        SecretService::withLock(static function () use ($payload, $schemaKeys, &$result, &$clean, &$current, &$next, &$ok, &$freeformFailed) {
            $ok = SecretService::updateAgentSecrets(static function (array $cur) use ($payload, &$result, &$clean, &$current, &$next) {
                $current = $cur;
                $emptied = [];
                $rejected = [];
                foreach ($payload as $k => $v) {
                    $k = (string)$k; $v = (string)$v;
                    // Secret key shape matches the existing vault: UPPER_SNAKE.
                    if (!preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $k)) {
                        $rejected[] = [$k, 'invalid secret name (UPPER_SNAKE, 2 to 128 characters)'];
                        continue;
                    }
                    if (EnvService::isReservedKey($k)) {
                        $rejected[] = [$k, 'reserved name (managed by the plugin runtime)'];
                        continue;
                    }
                    // The UI renders a set value as the masked placeholder. If the user
                    // didn't retype it, "keep existing" — carry the current value forward.
                    if ($v === self::MASKED_PLACEHOLDER) {
                        if (isset($cur[$k]) && (string)$cur[$k] !== '') $clean[$k] = (string)$cur[$k];
                        continue;
                    }
                    // An empty value clears the stored value (#669). Without this a schema key,
                    // which is never free-form, could not be cleared by this save at all.
                    if ($v === '') { $emptied[] = $k; continue; }
                    $clean[$k] = EnvService::sanitiseValue($v);
                }
                if (!empty($rejected)) { // #666 R-154: save nothing, so a refused rename keeps the old value
                    $result = ['status' => 'error', 'message' => self::rejectionMsg($rejected)];
                    return null;
                }
                // Drop existing free-form keys (about to be replaced wholesale by the POST);
                // drop a key the POST sent empty (cleared); keep everything else (schema-managed
                // keys, and — conservatively — any pre-feature key that's in neither the schema
                // nor the free-form sidecar).
                $freeformKeys = SecretService::getFreeformKeys();
                foreach ($cur as $k => $v) {
                    if (!in_array($k, $freeformKeys, true) && !in_array($k, $emptied, true)) $next[$k] = $v;
                }
                foreach ($clean as $k => $v) {
                    $next[$k] = $v;
                }
                return $next;
            });
            if (!$ok || $result !== null) return;
            // Rebuild the sidecar to mirror the new free-form set (only keys that
            // also aren't in the schema — paranoid: a user shouldn't be able to
            // sneak a schema name into the free-form list).
            $newFreeform = [];
            foreach (array_keys($clean) as $k) {
                if (!in_array($k, $schemaKeys, true)) $newFreeform[] = $k;
            }
            $freeformFailed = !SecretService::updateFreeformKeys(static fn(array $old) => $newFreeform);
        }, $busy);
        if ($result !== null) return $result;
        if ($busy || !$ok) return ['status' => 'error', 'message' => SecretService::lastUpdateMessage() ?? 'The secrets could not be saved. Retry in a moment.'];
        if ($freeformFailed) {
            return ['status' => 'error', 'message' => 'The secrets were saved, but the list of free-form names was not. ' . (SecretService::lastUpdateMessage() ?? '')];
        }

        return self::withNotice(['status' => 'ok', 'count' => count($clean), 'rejected' => [], 'changed' => !self::sameMap($current, $next)]);
    }

    // ---------- Secret — workspace ----------

    private static function getWorkspaceSecrets(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = (string)($_REQUEST['path'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if ($path === '') return ['status' => 'error', 'message' => 'Missing path'];

        return [
            'status'        => 'ok',
            'secrets'       => SecretService::getWorkspaceSecretsForUI($path, $agentId),
            'agentDefaults' => SecretService::getAgentSecretsForUI(), // for inherit/diff display (has_value only)
        ];
    }

    private static function saveWorkspaceSecrets(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = (string)($_REQUEST['path'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];
        if ($path === '') return ['status' => 'error', 'message' => 'Missing path'];

        $payload = self::decodeMapPayload();
        $current = [];
        $clean = [];
        $result = null;
        // #890: read, change and write under the secret lock.
        $ok = SecretService::updateWorkspaceSecrets($path, $agentId, static function (array $cur) use ($payload, &$current, &$clean, &$result) {
            $current = $cur;
            $rejected = [];
            foreach ($payload as $k => $v) {
                $k = (string)$k; $v = (string)$v;
                if (!preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $k)) {
                    $rejected[] = [$k, 'invalid secret name (UPPER_SNAKE, 2 to 128 characters)'];
                    continue;
                }
                if (EnvService::isReservedKey($k)) {
                    $rejected[] = [$k, 'reserved name'];
                    continue;
                }
                if ($v === self::MASKED_PLACEHOLDER) {
                    if (isset($cur[$k]) && (string)$cur[$k] !== '') $clean[$k] = (string)$cur[$k];
                    continue;
                }
                if ($v === '') continue;
                $clean[$k] = EnvService::sanitiseValue($v);
            }
            if (!empty($rejected)) { // #666 R-154: save nothing, so a refused rename keeps the old value
                $result = ['status' => 'error', 'message' => self::rejectionMsg($rejected)];
                return null;
            }
            return $clean;
        });
        if ($result !== null) return $result;
        // #463: `changed` lets the page skip the "restart to use the new values" notice
        // when the save left every value as it was.
        return $ok
            ? self::withNotice(['status' => 'ok', 'count' => count($clean), 'rejected' => [], 'changed' => !self::sameMap($current, $clean)])
            : ['status' => 'error', 'message' => SecretService::lastUpdateMessage() ?? 'Write failed'];
    }

    // ---------- Effective merge view (for UI inherit/diff display) ----------

    private static function getEffectiveEnvs(): array {
        $agentId = ValidationService::validateId($_GET['agentId'] ?? '');
        $path    = (string)($_REQUEST['path'] ?? '');
        if (!$agentId) return ['status' => 'error', 'message' => 'Missing or invalid agentId'];

        $effective = EnvService::buildEffectiveEnv($path !== '' ? $path : null, $agentId);
        // Mask the value of every key that is a secret (#588). A secret is a key that
        // a registry schema declares OR a key held in the agent vault or the
        // workspace vault. The vault test matters: a free-form secret that no agent
        // declares is in no schema list, and its value must not leave the box.
        $secretKeys = array_fill_keys(self::collectAllSchemaSecretKeys(), true);
        foreach (array_keys(SecretService::getAgentSecrets()) as $k) $secretKeys[(string)$k] = true;
        if ($path !== '') {
            foreach (array_keys(SecretService::getWorkspaceSecrets($path, $agentId)) as $k) $secretKeys[(string)$k] = true;
        }
        $masked = [];
        foreach ($effective as $k => $v) {
            $masked[$k] = isset($secretKeys[(string)$k]) ? ['has_value' => ((string)$v !== '')] : ['value' => (string)$v];
        }
        return ['status' => 'ok', 'effective' => $masked];
    }

    // ---------- Helpers ----------

    /**
     * POSTed env maps arrive either as JSON (when the UI sends a single JSON
     * body field) or as PHP $_POST array (a flat form encoding). Accept both.
     */
    private static function decodeMapPayload(): array {
        // Preferred: a single JSON-encoded `envs` field (mirrors save_env).
        if (isset($_REQUEST['envs'])) {
            $decoded = json_decode((string)$_REQUEST['envs'], true);
            if (is_array($decoded)) return $decoded;
        }
        if (isset($_REQUEST['secrets'])) {
            $decoded = json_decode((string)$_REQUEST['secrets'], true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    /** True when two name => value maps hold the same pairs, whatever their order. */
    private static function sameMap(array $a, array $b): bool {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    private static function rejectionMsg(array $rejected): string {
        $bits = [];
        foreach ($rejected as [$k, $why]) {
            $bits[] = "$k ($why)";
        }
        return 'Nothing was saved. Rejected: ' . implode('; ', $bits);
    }

    /**
     * Collect every declared secret env name across the entire agent registry.
     * Used by save_agent_secrets to know which existing-vault keys are
     * schema-managed (must not be touched by the free-form save path).
     *
     * Placeholder-bearing names like '{GOOSE_PROVIDER}_API_KEY' are resolved
     * against current vault values so the dynamic name (e.g. 'ANTHROPIC_API_KEY')
     * also counts as schema-managed.
     */
    private static function collectAllSchemaSecretKeys(): array {
        $out = [];
        $registry = AgentRegistry::getRegistry();
        $vault = SecretService::getAgentSecrets();

        foreach ($registry as $agent) {
            $secrets = $agent['default_secrets'] ?? $agent['secrets'] ?? [];
            if (!is_array($secrets)) continue;
            foreach ($secrets as $sec) {
                $env = $sec['env'] ?? '';
                if ($env === '') continue;
                // Literal name.
                if (!preg_match('/\{([A-Z_][A-Z0-9_]*)\}/', $env, $m)) {
                    $out[$env] = true;
                    continue;
                }
                // Placeholder-bearing — try to resolve against current vault.
                $placeholder = $m[1];
                $subst = $vault[$placeholder] ?? '';
                if ($subst !== '') {
                    $resolved = str_replace($m[0], strtoupper($subst), $env);
                    if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $resolved)) {
                        $out[$resolved] = true;
                    }
                }
                // Also mark the placeholder source key itself (e.g. GOOSE_PROVIDER) as schema.
                $out[$placeholder] = true;
            }
        }
        return array_keys($out);
    }
}
