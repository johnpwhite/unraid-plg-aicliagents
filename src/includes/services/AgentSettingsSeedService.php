<?php
/**
 * <module_context>
 *     <name>AgentSettingsSeedService</name>
 *     <description>Manifest-driven seeding of defaults into an agent's OWN JSON
 *     config file, for settings that have no env var or CLI flag equivalent.
 *     The sibling of EnvService::seedAgentDefaults() (which seeds environment
 *     variables) — same additive, skip-if-user-set, sidecar-tracked contract, a
 *     different destination.</description>
 *     <dependencies>AgentRegistry, HubProjector, AtomicWriteService, ConfigService, LogService</dependencies>
 *     <constraints>NEVER rewrites an unparseable non-empty file. NEVER overwrites
 *     a value the user already set. NEVER re-seeds a key the sidecar records, so a
 *     deliberate deletion is not resurrected. Decodes assoc=false so empty objects
 *     ({} vs []) elsewhere in the file survive the round-trip.</constraints>
 * </module_context>
 *
 * Why this exists (docs/specs/AGENT_SELF_UPDATE_SUPPRESSION.md):
 * gemini-cli and qwen-code both publish a verified way to turn their own
 * auto-update off — `general.enableAutoUpdate: false` — but ONLY as a
 * settings.json key. Neither honours an env var (GEMINI_CLI_ENABLE_AUTO_UPDATE
 * was tried on 2026-05-11 and removed because gemini-cli ignores it) and neither
 * has a CLI flag. Both were therefore listed SELF_UPDATE_EXEMPT with the reason
 * "this plugin has no facility to seed an agent's own JSON config file" — which
 * was wrong: Config Hub already writes both files. Config Hub is the wrong
 * vehicle even so, because MCP projection is opt-in and drift-gated, so a user
 * who never enables it would never get the suppression. This service is the
 * right vehicle: it runs on the same unconditional install / plugin-upgrade
 * path that seeds default_envs.
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Hub\HubProjector;

class AgentSettingsSeedService {

    /** Sidecar of keys this plugin has already seeded for an agent. */
    public static function getSeededSidecarPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/envs/seeded_settings_{$agentId}.json";
    }

    /**
     * Seed one agent's `default_settings` manifest entry into its own config
     * file. $manifestOverride and $homeOverride exist for tests, so the seed
     * algorithm can be exercised without a mounted managed home.
     *
     * @return array{seeded:string[],skipped:array<array{0:string,1:string}>,errors:string[]}
     */
    public static function seedAgent(string $agentId, ?array $manifestOverride = null, ?string $homeOverride = null): array {
        $empty = ['seeded' => [], 'skipped' => [], 'errors' => []];

        if ($manifestOverride !== null) {
            $manifest = $manifestOverride;
        } else {
            $registry = AgentRegistry::getRegistry();
            $agent    = $registry[$agentId] ?? null;
            $manifest = is_array($agent) ? ($agent['default_settings'] ?? []) : [];
        }
        if (!is_array($manifest) || empty($manifest)) return $empty;

        $rel    = (string)($manifest['file'] ?? '');
        $values = $manifest['values'] ?? [];
        if ($rel === '' || !is_array($values) || empty($values)) return $empty;

        // HOME-relative, and it must stay under HOME.
        if ($rel[0] === '/' || strpos($rel, '..') !== false) {
            LogService::log("seedSettings: $agentId rejected unsafe path '$rel'", LogService::LOG_ERROR, 'AgentSettingsSeed');
            return ['seeded' => [], 'skipped' => [], 'errors' => ['unsafe_path']];
        }

        if ($homeOverride !== null) {
            $home = rtrim($homeOverride, '/');
        } else {
            $resolved = HubProjector::resolveHome();
            if (empty($resolved['ok'])) {
                // No implicit mounting: a write against the bare tmpfs path would
                // be lost at the next mount/bake. Leave the sidecar untouched so
                // the next run (plugin upgrade sweep) retries.
                return ['seeded' => [], 'skipped' => [], 'errors' => [(string)($resolved['error'] ?? 'home_unavailable')]];
            }
            $home = rtrim((string)$resolved['home'], '/');
        }

        $file = "$home/$rel";
        $raw  = is_file($file) ? (string)@file_get_contents($file) : '';
        if (trim($raw) !== '') {
            $data = json_decode($raw);
            if (!($data instanceof \stdClass)) {
                LogService::log("seedSettings: $agentId refused to rewrite unparseable $rel", LogService::LOG_WARN, 'AgentSettingsSeed');
                return ['seeded' => [], 'skipped' => [], 'errors' => ['unparseable']];
            }
        } else {
            $data = new \stdClass();
        }

        $sidecar = self::readSeededSidecar($agentId);
        $seeded  = [];
        $skipped = [];

        foreach ($values as $path => $value) {
            $path = (string)$path;
            if ($path === '' || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $path)) {
                $skipped[] = [$path, 'invalid key'];
                continue;
            }
            if (in_array($path, $sidecar, true)) {
                $skipped[] = [$path, 'previously seeded — user may have deleted'];
                continue;
            }
            if (self::pathExists($data, $path)) {
                $skipped[] = [$path, 'user value present'];
                continue;
            }
            self::setPath($data, $path, $value);
            $sidecar[] = $path;
            $seeded[]  = $path;
        }

        if (!empty($seeded)) {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['seeded' => [], 'skipped' => $skipped, 'errors' => ['mkdir_failed']];
            }
            if (!AtomicWriteService::writeJson($file, $data)) {
                return ['seeded' => [], 'skipped' => $skipped, 'errors' => ['write_failed']];
            }
            self::writeSeededSidecar($agentId, $sidecar);
            LogService::log("seedSettings: $agentId — seeded " . implode(',', $seeded) . " into $rel", LogService::LOG_INFO, 'AgentSettingsSeed');
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'errors' => []];
    }

    /**
     * Walk the registry; for every installed agent with a `default_settings`
     * manifest entry, run seedAgent. Called from InstallerService after an
     * install and from the PLG INLINE upgrade block, exactly like
     * EnvService::seedAllInstalledAgentDefaults().
     */
    public static function seedAllInstalledAgents(): array {
        $registry = AgentRegistry::getRegistry();
        $summary  = ['agents' => 0, 'seeded_total' => 0, 'agents_touched' => [], 'errors' => []];
        foreach ($registry as $id => $agent) {
            if (empty($agent['is_installed'])) continue;
            if (empty($agent['default_settings'])) continue;
            $r = self::seedAgent((string)$id);
            $summary['agents']++;
            if (!empty($r['seeded'])) {
                $summary['seeded_total'] += count($r['seeded']);
                $summary['agents_touched'][] = $id;
            }
            foreach ($r['errors'] as $e) $summary['errors'][] = "$id:$e";
        }
        return $summary;
    }

    // ---------- Internal ----------

    /** True when the dotted path already resolves to something in $data. */
    private static function pathExists(\stdClass $data, string $path): bool {
        $node = $data;
        foreach (explode('.', $path) as $seg) {
            if (!($node instanceof \stdClass) || !property_exists($node, $seg)) return false;
            $node = $node->{$seg};
        }
        return true;
    }

    /** Set the dotted path, creating intermediate objects. */
    private static function setPath(\stdClass $data, string $path, $value): void {
        $segs = explode('.', $path);
        $last = array_pop($segs);
        $node = $data;
        foreach ($segs as $seg) {
            if (!property_exists($node, $seg) || !($node->{$seg} instanceof \stdClass)) {
                $node->{$seg} = new \stdClass();
            }
            $node = $node->{$seg};
        }
        $node->{$last} = $value;
    }

    private static function readSeededSidecar(string $agentId): array {
        $file = self::getSeededSidecarPath($agentId);
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private static function writeSeededSidecar(string $agentId, array $keys): bool {
        return AtomicWriteService::writeJson(self::getSeededSidecarPath($agentId), array_values(array_unique($keys)));
    }
}
