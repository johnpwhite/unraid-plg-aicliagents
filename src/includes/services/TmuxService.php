<?php
/**
 * <module_context>
 *     <name>TmuxService</name>
 *     <description>Four-tier tmux settings resolver: built-in → agent-default → workspace-override → workspace .conf. Diff-detect save semantics (only divergent keys persisted).</description>
 *     <dependencies>ConfigService, LogService</dependencies>
 *     <constraints>Allowlisted keys only. Uses proc_open array form (no shell).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TmuxService {

    const ALLOWED_KEYS = [
        'status','mouse','history-limit','prefix','base-index',
        'bell-action','default-terminal','focus-events','allow-passthrough'
    ];

    /**
     * Built-in defaults. These are what aicli-shell.sh sets before any JSON is loaded.
     * Kept in sync with the shell — any change here needs a paired shell edit.
     */
    const BUILTIN = [
        'status'            => 'off',
        'mouse'             => 'off',
        'history-limit'     => '10000',
        'prefix'            => 'C-b',
        'base-index'        => '0',
        'bell-action'       => 'any',
        'default-terminal'  => 'screen',
        'focus-events'      => 'on',
        'allow-passthrough' => 'on',
    ];

    // ---------- Paths ----------

    public static function getAgentSettingsPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/tmux/tmux_agent_{$agentId}.json";
    }

    public static function getWorkspaceSettingsPath(string $path, string $agentId): string {
        $hash = md5($path);
        return ConfigService::getUserStatePath() . "/tmux/tmux_ws_{$hash}_{$agentId}.json";
    }

    public static function getConfPath(string $path, string $agentId): string {
        return rtrim($path, '/') . '/.aicli/tmux/' . $agentId . '.conf';
    }

    /** Legacy layout (md5($path.$agentId) hash). Retained for the migration scan only. */
    public static function getLegacyFilePath(string $path, string $agentId): string {
        $hash = md5($path . $agentId);
        return ConfigService::getUserStatePath() . "/tmux/tmux_{$hash}.json";
    }

    // ---------- Tier accessors ----------

    public static function getAgentDefaults(string $agentId): array {
        return self::readJsonFiltered(self::getAgentSettingsPath($agentId));
    }

    public static function getWorkspaceOverrides(string $path, string $agentId): array {
        return self::readJsonFiltered(self::getWorkspaceSettingsPath($path, $agentId));
    }

    // ---------- Tier setters (diff-detect semantics) ----------

    /**
     * Save agent defaults: write only keys that differ from the built-in tier.
     * Delete the file when no divergent keys remain (zero-noise state).
     */
    public static function saveAgentDefaults(string $agentId, array $settings): bool {
        $diff = self::diffAgainst($settings, self::BUILTIN);
        return self::writeOrUnlink(self::getAgentSettingsPath($agentId), $diff, "agent/$agentId");
    }

    /**
     * Save workspace overrides: write only keys that differ from the effective agent default.
     * Agent-default fields that the workspace matches don't get persisted; they flow through
     * from the agent tier at launch.
     */
    public static function saveWorkspaceOverrides(string $path, string $agentId, array $settings): bool {
        $agentDefaults = array_merge(self::BUILTIN, self::getAgentDefaults($agentId));
        $diff = self::diffAgainst($settings, $agentDefaults);
        return self::writeOrUnlink(self::getWorkspaceSettingsPath($path, $agentId), $diff, "workspace/$path/$agentId");
    }

    /**
     * Compute the effective merged settings and source attribution.
     * Returns [key => ['value' => ..., 'source' => 'builtin'|'agent'|'workspace'|'conf']].
     * The .conf tier is detected by presence of the file — its actual values are opaque to
     * the UI (it can be any tmux command), so we attribute to 'conf' when the file exists
     * and leave value resolution to the live tmux run-time.
     */
    public static function getEffectiveSettings(string $path, string $agentId): array {
        $agent     = self::getAgentDefaults($agentId);
        $workspace = self::getWorkspaceOverrides($path, $agentId);
        $confExists = file_exists(self::getConfPath($path, $agentId));

        $effective = [];
        foreach (self::ALLOWED_KEYS as $k) {
            if (isset($workspace[$k])) {
                $effective[$k] = ['value' => $workspace[$k], 'source' => 'workspace'];
            } elseif (isset($agent[$k])) {
                $effective[$k] = ['value' => $agent[$k], 'source' => 'agent'];
            } else {
                $effective[$k] = ['value' => self::BUILTIN[$k], 'source' => 'builtin'];
            }
        }
        $effective['_conf_present'] = $confExists;
        return $effective;
    }

    // ---------- Legacy migration ----------

    /**
     * Rename old per-(workspace, agent) hash-keyed configs to .legacy so the new launch
     * path doesn't silently pick them up. Idempotent — safe to call every launch.
     * @return int number of files renamed
     */
    public static function renameLegacyFiles(): int {
        $dir = ConfigService::getUserStatePath() . '/tmux';
        if (!is_dir($dir)) return 0;
        $count = 0;
        foreach (glob("$dir/tmux_*.json") ?: [] as $f) {
            $base = basename($f);
            // Skip new-format files.
            if (strpos($base, 'tmux_agent_') === 0) continue;
            if (strpos($base, 'tmux_ws_') === 0) continue;
            // Match only the legacy 32-char hex hash pattern.
            if (preg_match('/^tmux_[a-f0-9]{32}\.json$/', $base)) {
                if (@rename($f, "$f.legacy")) {
                    $count++;
                    LogService::log("Renamed legacy tmux config: $base -> $base.legacy", LogService::LOG_WARN, "TmuxService");
                }
            }
        }
        return $count;
    }

    // ---------- Live operations (unchanged semantics) ----------

    public static function applySettings(string $path, string $agentId): array {
        // Apply the merged agent+workspace tier — what the user currently sees in the UI.
        $merged = array_merge(self::getAgentDefaults($agentId), self::getWorkspaceOverrides($path, $agentId));
        $applied = [];
        $errors = [];
        foreach ($merged as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) continue;
            if ($v === '' || $v === null) continue;
            $r = self::runTmux(['set-option', '-g', $k, (string)$v]);
            if ($r['rc'] === 0) $applied[] = $k; else $errors[$k] = $r['err'] ?: $r['out'];
        }
        return ['applied' => $applied, 'errors' => $errors];
    }

    public static function reloadConf(string $path, string $agentId): array {
        $conf = self::getConfPath($path, $agentId);
        if (!file_exists($conf) || !is_readable($conf)) {
            return ['status' => 'error', 'message' => "No conf file at $conf"];
        }
        $r = self::runTmux(['source-file', $conf]);
        return $r['rc'] === 0
            ? ['status' => 'ok', 'conf' => $conf]
            : ['status' => 'error', 'message' => $r['err'] ?: $r['out'], 'conf' => $conf];
    }

    public static function killSessions(string $agentId, ?string $sessionId = null): array {
        $killed = [];
        if ($sessionId) {
            $target = "aicli-agent-{$agentId}-{$sessionId}";
            $r = self::runTmux(['kill-session', '-t', $target]);
            if ($r['rc'] === 0) $killed[] = $target;
            return $killed;
        }
        $prefix = "aicli-agent-{$agentId}-";
        $r = self::runTmux(['list-sessions', '-F', '#{session_name}']);
        if ($r['rc'] !== 0 || $r['out'] === '') return $killed;
        foreach (explode("\n", $r['out']) as $name) {
            $name = trim($name);
            if (strpos($name, $prefix) === 0) {
                $k = self::runTmux(['kill-session', '-t', $name]);
                if ($k['rc'] === 0) $killed[] = $name;
            }
        }
        return $killed;
    }

    // ---------- Back-compat shims (existing handler may still call these) ----------

    public static function getSettings(string $path, string $agentId): array {
        // Back-compat: return the merged agent+workspace view the old single-file callers expected.
        return array_merge(self::getAgentDefaults($agentId), self::getWorkspaceOverrides($path, $agentId));
    }

    public static function saveSettings(string $path, string $agentId, array $settings): bool {
        // Back-compat: route to workspace-override tier. New callers should use the explicit tier methods.
        return self::saveWorkspaceOverrides($path, $agentId, $settings);
    }

    // ---------- Internals ----------

    private static function readJsonFiltered(string $file): array {
        if (!file_exists($file)) return [];
        $data = json_decode(@file_get_contents($file), true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, self::ALLOWED_KEYS, true) && $v !== '' && $v !== null) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }

    /**
     * Return the subset of $settings whose values differ from $baseline. Unknown / disallowed
     * keys are dropped. Empty/null values are also dropped — treat as "revert to baseline".
     */
    private static function diffAgainst(array $settings, array $baseline): array {
        $diff = [];
        foreach ($settings as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) continue;
            if ($v === '' || $v === null) continue;
            if (!isset($baseline[$k]) || (string)$baseline[$k] !== (string)$v) {
                $diff[$k] = (string)$v;
            }
        }
        return $diff;
    }

    private static function writeOrUnlink(string $file, array $diff, string $label): bool {
        if (!empty($diff)) {
            if (!AtomicWriteService::writeJson($file, $diff)) {
                LogService::log("Failed to save tmux settings ($label) to $file", LogService::LOG_ERROR, "TmuxService");
                return false;
            }
            LogService::log("Saved tmux settings ($label): " . count($diff) . " divergent keys", LogService::LOG_INFO, "TmuxService");
        } else {
            if (file_exists($file)) {
                @unlink($file);
                LogService::log("Cleared tmux settings ($label) — all values match baseline", LogService::LOG_INFO, "TmuxService");
            }
        }
        return true;
    }

    /**
     * Run tmux with an argv array (no shell). Eliminates shell-injection risk:
     * arguments pass directly to execve() without word-splitting or globbing.
     */
    // nosemgrep: php.lang.security.exec-use.exec-use
    private static function runTmux(array $args): array {
        $argv = array_merge(['tmux'], $args);
        // nosemgrep: php.lang.security.exec-use.exec-use
        $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return ['rc' => -1, 'out' => '', 'err' => 'proc_open failed'];
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        $rc = proc_close($proc);
        return ['rc' => $rc, 'out' => trim($stdout), 'err' => trim($stderr)];
    }
}
