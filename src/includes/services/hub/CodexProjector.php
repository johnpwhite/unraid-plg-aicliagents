<?php
/**
 * <module_context>
 *     <name>CodexProjector</name>
 *     <description>Codex CLI MCP projection into ~/.codex/config.toml (OP #1362 / H-01).
 *     TOML vendor: managed servers are emitted inside a comment-fenced block
 *     (`# &gt;&gt;&gt; aicli-hub managed — do not edit inside &gt;&gt;&gt;` … `# &lt;&lt;&lt; aicli-hub &lt;&lt;&lt;`)
 *     appended at EOF / replaced in place on each run. TOML tables are position-
 *     independent so EOF placement is safe. Keys outside the fence are never touched.
 *     Drift hashes the full fence content (one managed key per fence). A second fence
 *     (key aicli-env-policy) holds the [shell_environment_policy] table that lets the
 *     AICLI_* variables reach Codex's command tool (#688). It is skipped, and reported
 *     as a conflict, when the user sets shell_environment_policy outside the fence.</description>
 *     <dependencies>VendorProjector, Transpiler</dependencies>
 *     <constraints>Idempotent: projecting twice yields a byte-identical file.
 *     ~/.codex/auth.json is never read or written.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class CodexProjector extends VendorProjector {

    const FENCE_KEY   = 'aicli-hub-fence';
    const FENCE_OPEN  = '# >>> aicli-hub managed — do not edit inside >>>';
    const FENCE_CLOSE = '# <<< aicli-hub <<<';

    public function agentId(): string { return 'codex-cli'; }
    public function relPath(): string { return '.codex/config.toml'; }
    public function label(): string   { return 'Codex CLI'; }

    /**
     * Second managed block (#688). It holds the `[shell_environment_policy]` table. Codex builds
     * the environment of each command from this table. The block sets `inherit = "all"`, so the
     * AICLI_* variables (AICLI_RELAY_COMMAND, AICLI_ADMIN_COMMAND, AICLI_SESSION_ID) reach the
     * commands that the Codex command tool runs. It keeps the default exclude list for names
     * that hold KEY, SECRET or TOKEN. The key names were checked against the installed Codex
     * binary: inherit, ignore_default_excludes, exclude, set, include_only, filters,
     * experimental_use_profile. It is a separate key from the MCP block, so a user's own
     * policy blocks only this block and never the MCP servers.
     */
    const POLICY_KEY   = 'aicli-env-policy';
    const POLICY_OPEN  = '# >>> aicli-hub env policy — do not edit inside >>>';
    const POLICY_CLOSE = '# <<< aicli-hub env policy <<<';

    public function desired(array $servers): array {
        $out = [];
        if (!empty($servers)) {
            $out[self::FENCE_KEY] = self::FENCE_OPEN . "\n" . Transpiler::toCodexToml($servers, $this->headersKey()) . "\n" . self::FENCE_CLOSE;
        }
        $policy = $this->envPolicyBody();
        if ($policy !== null) {
            $out[self::POLICY_KEY] = self::POLICY_OPEN . "\n" . $policy . "\n" . self::POLICY_CLOSE;
        }
        return $out;
    }

    /**
     * Text of the environment policy table, or null when this agent has no such table.
     * Codex only. A child class (Grok Build) returns null.
     */
    protected function envPolicyBody(): ?string {
        return "# Keep the AICLI_* variables in the environment of each command that Codex runs.\n"
            . "[shell_environment_policy]\n"
            . 'inherit = "all"';
    }

    /** TOML sub-table that carries a remote server's custom HTTP headers. */
    protected function headersKey(): string { return 'http_headers'; }

    /** @return array<string,array{0:string,1:string}> managed key => [open marker, close marker] */
    private static function fences(): array {
        return [
            self::FENCE_KEY  => [self::FENCE_OPEN, self::FENCE_CLOSE],
            self::POLICY_KEY => [self::POLICY_OPEN, self::POLICY_CLOSE],
        ];
    }

    public function current(string $file, array $keys): array {
        if (!is_file($file)) return [];
        $raw = (string)@file_get_contents($file);
        $out = [];
        foreach (self::fences() as $key => [$open, $close]) {
            if (!in_array($key, $keys, true)) continue;
            $block = self::extractFence($raw, $open, $close);
            if ($block !== null) $out[$key] = $block;
        }
        return $out;
    }

    /** True when the TOML text sets shell_environment_policy itself, as a table, a sub-table, a dotted key or an inline table. */
    public static function definesShellEnvironmentPolicy(string $text): bool {
        $name = '(?:shell_environment_policy|"shell_environment_policy"|\'shell_environment_policy\')';
        return (bool)preg_match('/^[ \t]*\[[ \t]*' . $name . '[ \t]*[\].]/m', $text)
            || (bool)preg_match('/^[ \t]*' . $name . '[ \t]*[.=]/m', $text);
    }

    /** Server names a TOML text defines as [mcp_servers.NAME...] tables or mcp_servers.NAME.key lines. */
    public static function tomlServerNames(string $text): array {
        $name = '(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z0-9_-]+))';
        $names = [];
        $res = [
            '/^[ \t]*\[[ \t]*mcp_servers[ \t]*\.[ \t]*' . $name . '[ \t]*[\].]/m',
            '/^[ \t]*mcp_servers[ \t]*\.[ \t]*' . $name . '[ \t]*[.=]/m',
        ];
        foreach ($res as $re) {
            if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) continue;
            foreach ($m as $hit) { $hit = array_pad($hit, 4, ''); $names[] =$hit[1] !== '' ? $hit[1] : ($hit[2] !== '' ? $hit[2] : $hit[3]); }
        }
        return array_values(array_unique($names));
    }

    public function conflictsOutside(string $file, $value): array {
        if (!is_file($file) || !is_string($value)) return [];
        $raw = (string)@file_get_contents($file);
        if (strpos($value, self::POLICY_OPEN) === 0) {
            // The policy block. A table that the user wrote outside the block wins (#688):
            // Codex rejects a file with two [shell_environment_policy] tables.
            $own = self::extractFence($raw, self::POLICY_OPEN, self::POLICY_CLOSE);
            $outside = $own === null ? $raw : str_replace($own, '', $raw);
            return self::definesShellEnvironmentPolicy($outside) ? ['shell_environment_policy'] : [];
        }
        $fence = self::extractFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        $outside = $fence === null ? $raw : str_replace($fence, '', $raw);
        $mine = self::tomlServerNames($value);
        $theirs = self::tomlServerNames($outside);
        $hit = array_values(array_intersect($mine, $theirs));
        sort($hit);
        return $hit;
    }

    public function write(string $file, array $set, array $remove): bool {
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        $touched = false;
        foreach (self::fences() as $key => [$open, $close]) {
            if (array_key_exists($key, $set)) {
                // A same-name table outside the fence would make a duplicate key; Codex then rejects the file (#642).
                if (!empty($this->conflictsOutside($file, (string)$set[$key]))) return false;
                $raw = self::replaceOrAppendFence($raw, (string)$set[$key], $open, $close);
                $touched = true;
            } elseif (in_array($key, $remove, true)) {
                $raw = self::stripFence($raw, $open, $close);
                $touched = true;
            }
        }
        if (!$touched) return true; // nothing to do
        return $this->atomicWrite($file, $raw);
    }

    // ---------- fence helpers (shared with GooseProjector via static calls) ----------

    /** Extract the fenced block (markers included), or null if absent. */
    public static function extractFence(string $raw, string $open, string $close): ?string {
        $re = '/^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$/ms';
        return preg_match($re, $raw, $m) ? $m[0] : null;
    }

    /**
     * Replace an existing fence in place, or append at EOF (blank-line separated).
     * Deterministic: same inputs → same bytes (idempotency contract).
     */
    public static function replaceOrAppendFence(string $raw, string $block, string $open, string $close): string {
        $re = '/^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$/ms';
        if (preg_match($re, $raw)) {
            return (string)preg_replace($re, self::quoteReplacement($block), $raw, 1);
        }
        $base = rtrim($raw, "\n");
        return ($base === '' ? '' : $base . "\n\n") . $block . "\n";
    }

    /** Remove the fence and its preceding blank separator; leaves the rest untouched. */
    public static function stripFence(string $raw, string $open, string $close): string {
        $re = '/\n?\n?^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$\n?/ms';
        $out = (string)preg_replace($re, "\n", $raw, 1, $replaced);
        // #666 R-161: with no fence there is nothing to remove; leave the user's blank
        // lines exactly as they are.
        if ($replaced === 0) return $raw;
        $out = ltrim($out, "\n");
        return $out === '' ? '' : rtrim($out, "\n") . "\n";
    }

    /** Escape backslashes and $ in the replacement string for preg_replace. */
    protected static function quoteReplacement(string $s): string {
        return strtr($s, ['\\' => '\\\\', '$' => '\\$']);
    }
}
