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
        'bell-action','default-terminal','focus-events','allow-passthrough',
        'escape-time','set-clipboard','extended-keys',
    ];

    /**
     * Keys that use -ga (append) semantics in tmux. applySettings() and the
     * shell apply_tmux_json helper branch on this const to emit session-targeted
     * append semantics (`set-option -a -t`) so that
     * multiple terminal-feature/override fragments accumulate rather than
     * clobber each other.
     *
     * APPEND_KEYS are NOT in ALLOWED_KEYS by design — they are applied only
     * via the shell Tier-1 built-in block and are intentionally excluded from
     * the JSON-tier user-editable surface because append semantics make
     * per-tier diff-detect and revert logic non-trivial. T-07 quirk profiles
     * (future) will add a controlled path for agent-specific terminal-features.
     */
    const APPEND_KEYS = ['terminal-features', 'terminal-overrides'];

    /**
     * Keys that may be flipped LIVE on a single session via the
     * tmux_set_session_option action (T-04). Deliberately tiny: these are
     * `set-option -t <session>` (no -g, no JSON persistence) so they evaporate
     * with the session. Only `mouse` for now — the Copy-mode toggle.
     */
    const SESSION_SETTABLE_KEYS = ['mouse'];

    /**
     * Hard cap for tmux_paste_text payloads (T-06): 256 KB. Large enough for
     * any sane prompt/diff paste, small enough to keep a hostile paste from
     * ballooning tmux buffers or the PHP worker.
     */
    const PASTE_MAX_BYTES = 262144;

    /**
     * Built-in defaults. These are what aicli-shell.sh sets before any JSON is loaded.
     * Kept in sync with the shell — any change here needs a paired shell edit.
     */
    const BUILTIN = [
        'status'            => 'off',
        'mouse'             => 'off',
        'set-clipboard'     => 'on',   // T-02: required for OSC 52 pass-through (T-05)
        'history-limit'     => '10000',
        'prefix'            => 'C-b',
        'base-index'        => '0',
        'bell-action'       => 'any',
        'default-terminal'  => 'tmux-256color', // bundled terminfo; shell falls back to xterm-256color if it can't compile
        'focus-events'      => 'on',
        'allow-passthrough' => 'on',
        'escape-time'       => '0',   // WP #1253: avoid 500ms ESC mis-parse on fast TUI streams
        'extended-keys'     => 'off', // T-02: globally off; per-agent quirk profiles (T-07) enable it
    ];

    // ---------- Paths ----------

    public static function getAgentSettingsPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/tmux/tmux_agent_{$agentId}.json";
    }

    /**
     * Path where TerminalService::startTerminal writes the agent's resolved
     * quirk profile before launching ttyd. The shell Tier-1.5 apply_tmux_json
     * call reads this file. Lives in /tmp so it is ephemeral (no boot persistence
     * needed — it is always written fresh at session start).
     */
    public static function getQuirkPath(string $agentId): string {
        return "/tmp/unraid-aicliagents/tmux/quirks_{$agentId}.json";
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
     *
     * Returns [key => ['value' => ..., 'source' => 'builtin'|'agent-quirk'|'agent'|'workspace'|'conf']].
     *
     * Tier order (later wins):
     *   builtin      — TmuxService::BUILTIN (Tier 1)
     *   agent-quirk  — agent's tmux_profile in the registry (Tier 1.5, not user-editable)
     *   agent        — tmux_agent_<id>.json user-editable defaults (Tier 2)
     *   workspace    — tmux_ws_<hash>_<id>.json user-editable overrides (Tier 3)
     *   conf         — raw .conf file presence (Tier 4, opaque — value resolution deferred
     *                  to live tmux runtime)
     *
     * ALLOWED_KEYS only (APPEND_KEYS like terminal-features are not surfaced here because
     * they use -ga append semantics that diff-detect cannot model — they appear in the
     * agent-quirk tier via the shell's apply_tmux_json call instead).
     */
    public static function getEffectiveSettings(string $path, string $agentId): array {
        $quirks    = self::getAgentQuirks($agentId);
        $agent     = self::getAgentDefaults($agentId);
        $workspace = self::getWorkspaceOverrides($path, $agentId);
        $confExists = file_exists(self::getConfPath($path, $agentId));

        $effective = [];
        foreach (self::ALLOWED_KEYS as $k) {
            if (isset($workspace[$k])) {
                $effective[$k] = ['value' => $workspace[$k], 'source' => 'workspace'];
            } elseif (isset($agent[$k])) {
                $effective[$k] = ['value' => $agent[$k], 'source' => 'agent'];
            } elseif (isset($quirks[$k])) {
                $effective[$k] = ['value' => $quirks[$k], 'source' => 'agent-quirk'];
            } else {
                $effective[$k] = ['value' => self::BUILTIN[$k], 'source' => 'builtin'];
            }
        }
        $effective['_conf_present'] = $confExists;
        return $effective;
    }

    /**
     * Return the quirk profile for an agent from the registry's tmux_profile key.
     * Only keys in ALLOWED_KEYS or APPEND_KEYS are passed through — unknown keys
     * are silently dropped for security. Returns an empty array for agents that
     * carry no tmux_profile (safe default, no behaviour change).
     *
     * APPEND_KEYS (terminal-features, terminal-overrides) are included in the
     * returned map even though they are excluded from ALLOWED_KEYS: the shell
     * apply_tmux_json helper handles them with -ga semantics. They are NOT
     * surfaced in getEffectiveSettings() (which iterates ALLOWED_KEYS only).
     */
    public static function getAgentQuirks(string $agentId): array {
        // Lazy-load AgentRegistry to avoid circular dependency at class-load time.
        // The registry is always available by the time getEffectiveSettings is called.
        if (!class_exists('\AICliAgents\Services\AgentRegistry')) {
            $reg = '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/AgentRegistry.php';
            if (file_exists($reg)) require_once $reg;
        }
        if (!class_exists('\AICliAgents\Services\AgentRegistry')) {
            return [];
        }
        // Use the default registry only — quirk profiles are built-in, not user-customisable.
        $registry = \AICliAgents\Services\AgentRegistry::getDefaultAgents();
        $profile  = $registry[$agentId]['tmux_profile'] ?? null;
        if (!is_array($profile) || empty($profile)) {
            return [];
        }
        $allowed = array_merge(self::ALLOWED_KEYS, self::APPEND_KEYS);
        $out = [];
        foreach ($profile as $k => $v) {
            if (in_array($k, $allowed, true) && $v !== '' && $v !== null) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }

    /**
     * Write the agent's quirk profile to the tmp quirks file so the shell
     * Tier-1.5 block can consume it. Called by TerminalService::startTerminal
     * before launching ttyd. Idempotent — safe to call every launch.
     * Returns true on success or when there are no quirks to write.
     */
    public static function writeQuirkFile(string $agentId): bool {
        $quirks = self::getAgentQuirks($agentId);
        $dir = '/tmp/unraid-aicliagents/tmux';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = self::getQuirkPath($agentId);
        if (empty($quirks)) {
            // No profile — remove stale file so the shell gets an empty apply.
            if (file_exists($path)) @unlink($path);
            return true;
        }
        return (bool) file_put_contents($path, json_encode($quirks, JSON_PRETTY_PRINT));
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

    public static function applySettings(string $path, string $agentId, string $sessionId): array {
        [$session, $sock] = self::resolveSession($agentId, $sessionId);
        if ($session === '') return ['applied' => [], 'errors' => ['session' => 'Session not found']];
        // Apply the merged agent+workspace tier — what the user currently sees in the UI.
        $merged = array_merge(self::getAgentDefaults($agentId), self::getWorkspaceOverrides($path, $agentId));
        $applied = [];
        $errors = [];
        foreach ($merged as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) continue;
            if ($v === '' || $v === null) continue;
            // T-02 note: APPEND_KEYS (append semantics) are intentionally NOT
            // user-settable — the ALLOWED_KEYS guard above filters them.
            // The tmux server is shared. Apply to this workspace only; `-g`
            // would let one workspace overwrite every other workspace (#67).
            $r = self::runTmuxAt($sock, ['set-option', '-t', $session, $k, (string)$v]);
            if ($r['rc'] === 0) $applied[] = $k; else $errors[$k] = $r['err'] ?: $r['out'];
        }
        return ['applied' => $applied, 'errors' => $errors];
    }

    public static function reloadConf(string $path, string $agentId, string $sessionId): array {
        $conf = self::getConfPath($path, $agentId);
        if (!file_exists($conf) || !is_readable($conf)) {
            return ['status' => 'error', 'message' => "No conf file at $conf"];
        }
        [$session, $sock] = self::resolveSession($agentId, $sessionId);
        if ($session === '') return ['status' => 'error', 'message' => 'Session not found'];
        $r = self::runTmuxAt($sock, ['source-file', '-t', $session, $conf]);
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

    // ---------- Per-session live operations (T-04 / T-06) ----------

    /**
     * Flip an allowlisted option on ONE live session (`set-option -t`, never
     * -g, never persisted to any JSON tier). Used by the Copy-mode toggle.
     */
    public static function setSessionOption(string $agentId, string $sessionId, string $key, string $value): array {
        if (!in_array($key, self::SESSION_SETTABLE_KEYS, true)) {
            return ['status' => 'error', 'message' => "Option '$key' is not session-settable"];
        }
        if (!in_array($value, ['on', 'off'], true)) {
            return ['status' => 'error', 'message' => "Value must be 'on' or 'off'"];
        }
        [$name, $sock] = self::resolveSession($agentId, $sessionId);
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Session not found'];
        }
        $r = self::runTmuxAt($sock, ['set-option', '-t', $name, $key, $value]);
        if ($r['rc'] !== 0) {
            return ['status' => 'error', 'message' => $r['err'] ?: 'tmux set-option failed'];
        }
        LogService::log("Session option $key=$value applied to $name", LogService::LOG_INFO, "TmuxService");
        return ['status' => 'ok', 'key' => $key, 'value' => $value];
    }

    /**
     * Read the live value of an allowlisted session option (inheritance-aware
     * via `show-options -A`). Falls back to the BUILTIN default when the
     * session can't be resolved so the UI toggle still renders a sane state.
     */
    public static function getSessionOption(string $agentId, string $sessionId, string $key): array {
        if (!in_array($key, self::SESSION_SETTABLE_KEYS, true)) {
            return ['status' => 'error', 'message' => "Option '$key' is not session-settable"];
        }
        // SESSION_SETTABLE_KEYS ⊆ BUILTIN by contract (the allowlist check
        // above narrows $key to keys that exist in BUILTIN — phpstan-verified).
        $builtin = self::BUILTIN[$key];
        [$name, $sock] = self::resolveSession($agentId, $sessionId);
        if ($name === '') {
            return ['status' => 'ok', 'key' => $key, 'value' => $builtin, 'live' => false];
        }
        // -A includes options inherited from the global scope (inherited
        // entries render as "key* value").
        $r = self::runTmuxAt($sock, ['show-options', '-A', '-t', $name, $key]);
        $value = $builtin;
        if ($r['rc'] === 0 && preg_match('/^' . preg_quote($key, '/') . '\*?\s+(\S+)/m', $r['out'], $m)) {
            $value = $m[1];
        }
        return ['status' => 'ok', 'key' => $key, 'value' => $value, 'live' => true];
    }

    /**
     * Bracketed-paste arbitrary text into a live session (T-06).
     *
     * Pipeline: $text → `tmux load-buffer -b aicli-paste -` (fed via
     * proc_open stdin, array argv, no shell) → `tmux paste-buffer -p -d -b
     * aicli-paste -t <session>`. -p requests bracketed paste so TUI agents
     * treat it as a paste, not keystrokes; -d deletes the named buffer
     * afterwards so clipboard content does not linger in `tmux list-buffers`.
     *
     * SECURITY: $text is the user's clipboard. It must NEVER appear in any
     * log call, exception message, or returned error string — log byte
     * counts only. (Guarded by source assertion in TmuxPasteTextTest.)
     */
    public static function pasteText(string $agentId, string $sessionId, string $text): array {
        $bytes = strlen($text);
        if ($bytes === 0) {
            return ['status' => 'error', 'message' => 'Nothing to paste'];
        }
        if ($bytes > self::PASTE_MAX_BYTES) {
            return ['status' => 'error', 'message' => 'Paste exceeds the 256 KB limit'];
        }
        [$name, $sock] = self::resolveSession($agentId, $sessionId);
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Session not found'];
        }
        $load = self::runTmuxAt($sock, ['load-buffer', '-b', 'aicli-paste', '-'], $text);
        if ($load['rc'] !== 0) {
            LogService::log("tmux load-buffer failed for $name ($bytes bytes)", LogService::LOG_ERROR, "TmuxService");
            return ['status' => 'error', 'message' => 'tmux load-buffer failed'];
        }
        $paste = self::runTmuxAt($sock, ['paste-buffer', '-p', '-d', '-b', 'aicli-paste', '-t', $name]);
        if ($paste['rc'] !== 0) {
            // Best-effort buffer cleanup so the content doesn't strand in tmux.
            self::runTmuxAt($sock, ['delete-buffer', '-b', 'aicli-paste']);
            LogService::log("tmux paste-buffer failed for $name ($bytes bytes)", LogService::LOG_ERROR, "TmuxService");
            return ['status' => 'error', 'message' => 'tmux paste-buffer failed'];
        }
        LogService::log("Pasted $bytes bytes into $name", LogService::LOG_INFO, "TmuxService");
        return ['status' => 'ok', 'bytes' => $bytes];
    }

    /**
     * Resolve (agentId, sessionId) → [sessionName, socketPath] across the
     * per-uid tmux sockets under TMUX_TMPDIR. PHP runs as root but agent
     * sessions may live on another uid's socket, so the plain default-socket
     * client can't see them — ProcessManager::findTmuxSessionForId scans
     * every per-uid socket. The resolved name must equal the canonical
     * aicli-agent-<agentId>-<sessionId> (aicli-shell.sh naming) so a session
     * id can never be used to address another agent's session.
     *
     * @return array{0:string,1:string} ['', ''] when not found.
     */
    private static function resolveSession(string $agentId, string $sessionId): array {
        $agentId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId);
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($agentId === '' || $sessionId === '') return ['', ''];
        if (!class_exists('\AICliAgents\Services\ProcessManager')) {
            $pm = '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/ProcessManager.php';
            if (file_exists($pm)) require_once $pm;
        }
        if (!class_exists('\AICliAgents\Services\ProcessManager')) return ['', ''];
        [$name, $sock] = \AICliAgents\Services\ProcessManager::findTmuxSessionForId($sessionId);
        $expected = "aicli-agent-{$agentId}-{$sessionId}";
        if ($name === '' || $name !== $expected) return ['', ''];
        return [$name, $sock];
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
    private static function runTmux(array $args): array {
        return self::runTmuxAt('', $args);
    }

    /**
     * Run tmux against a specific server socket (-S), optionally feeding
     * stdin (used by `load-buffer -`). Same argv-array/no-shell guarantees
     * as runTmux. Empty $sock = default socket (root's TMUX_TMPDIR server).
     */
    // nosemgrep: php.lang.security.exec-use.exec-use
    private static function runTmuxAt(string $sock, array $args, ?string $stdin = null): array {
        $argv = array_merge(['tmux'], $sock !== '' ? ['-S', $sock] : [], $args);
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) $desc[0] = ['pipe', 'r'];
        // nosemgrep: php.lang.security.exec-use.exec-use
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return ['rc' => -1, 'out' => '', 'err' => 'proc_open failed'];
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
            unset($pipes[0]);
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        $rc = proc_close($proc);
        return ['rc' => $rc, 'out' => trim($stdout), 'err' => trim($stderr)];
    }
}
