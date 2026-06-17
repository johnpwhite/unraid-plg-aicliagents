<?php
/**
 * <module_context>
 *     <name>CodexProjector</name>
 *     <description>Codex CLI MCP projection into ~/.codex/config.toml (OP #1362 / H-01).
 *     TOML vendor: managed servers are emitted inside a comment-fenced block
 *     (`# &gt;&gt;&gt; aicli-hub managed — do not edit inside &gt;&gt;&gt;` … `# &lt;&lt;&lt; aicli-hub &lt;&lt;&lt;`)
 *     appended at EOF / replaced in place on each run. TOML tables are position-
 *     independent so EOF placement is safe. Keys outside the fence are never touched.
 *     Drift hashes the full fence content (single managed key per file).</description>
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

    public function desired(array $servers): array {
        if (empty($servers)) return [];
        return [self::FENCE_KEY => self::FENCE_OPEN . "\n" . Transpiler::toCodexToml($servers) . "\n" . self::FENCE_CLOSE];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        $block = self::extractFence((string)@file_get_contents($file), self::FENCE_OPEN, self::FENCE_CLOSE);
        return $block === null ? [] : [self::FENCE_KEY => $block];
    }

    public function write(string $file, array $set, array $remove): bool {
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        if (array_key_exists(self::FENCE_KEY, $set)) {
            $raw = self::replaceOrAppendFence($raw, (string)$set[self::FENCE_KEY], self::FENCE_OPEN, self::FENCE_CLOSE);
        } elseif (in_array(self::FENCE_KEY, $remove, true)) {
            $raw = self::stripFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        } else {
            return true; // nothing to do
        }
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
        $out = (string)preg_replace($re, "\n", $raw, 1);
        $out = ltrim($out, "\n");
        return $out === '' ? '' : rtrim($out, "\n") . "\n";
    }

    /** Escape backslashes and $ in the replacement string for preg_replace. */
    protected static function quoteReplacement(string $s): string {
        return strtr($s, ['\\' => '\\\\', '$' => '\\$']);
    }
}
