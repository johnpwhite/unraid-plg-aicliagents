<?php
/**
 * <module_context>
 *     <name>CodexProjector</name>
 *     <description>Codex CLI MCP projection into ~/.codex/config.toml (OP #1362 / H-01).
 *     TOML vendor: managed servers are emitted inside a comment-fenced block
 *     (`# &gt;&gt;&gt; aicli-hub managed — do not edit inside &gt;&gt;&gt;` … `# &lt;&lt;&lt; aicli-hub &lt;&lt;&lt;`)
 *     appended at EOF / replaced in place on each run. TOML tables are position-
 *     independent so EOF placement is safe. Keys outside the fence are never touched.
 *     Drift hashes only the tables the hub writes in the MCP fence (#970); tables that Codex adds there are kept, not hashed. A second fence
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
            // #970: Codex writes its own tables (tool approvals, projects, tui) into the fence.
            // Only the tables the hub writes count for the drift hash.
            if ($block !== null && $key === self::FENCE_KEY) $block = $this->managedView($block, $open, $close);
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
        // #970: a tool approval table of a managed server is not a clash.
        $outside = self::withoutToolTables($outside);
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
                $block = (string)$set[$key];
                if ($key === self::FENCE_KEY) {
                    // #970: keep the tables that Codex wrote into the fence.
                    [$block, $after] = $this->keepForeignTables($raw, $block);
                    if ($after !== '') $block .= "\n\n" . $after;
                }
                $raw = self::replaceOrAppendFence($raw, $block, $open, $close);
                $touched = true;
            } elseif (in_array($key, $remove, true)) {
                if ($key === self::FENCE_KEY) {
                    // #970: drop the fence but keep the foreign tables (projects, tui) that Codex put in it.
                    [, $after] = $this->keepForeignTables($raw, '');
                    $raw = $after === ''
                        ? self::stripFence($raw, $open, $close)
                        : self::replaceOrAppendFence($raw, $after, $open, $close);
                } else {
                    $raw = self::stripFence($raw, $open, $close);
                }
                $touched = true;
            }
        }
        if (!$touched) return true; // nothing to do
        return $this->atomicWrite($file, $raw);
    }

    // ---------- #970: tables inside the fence ----------

    /**
     * Segments of a TOML table header line, or null when the line is not a header.
     * @return array{0:string[],1:bool}|null [segments, isArrayTable]
     */
    private static function headerInfo(string $line): ?array {
        if ($line === '' || $line[0] !== '[') return null;
        $i = 1; $n = strlen($line); $arr = false;
        if ($i < $n && $line[$i] === '[') { $arr = true; $i++; }
        $segs = [];
        while (true) {
            while ($i < $n && ($line[$i] === ' ' || $line[$i] === "\t")) $i++;
            if ($i >= $n) return null;
            $c = $line[$i];
            if ($c === '"' || $c === "'") {
                $end = strpos($line, $c, $i + 1);
                if ($end === false) return null;
                $segs[] = substr($line, $i + 1, $end - $i - 1);
                $i = $end + 1;
            } else {
                if (!preg_match('/\G[A-Za-z0-9_-]+/', $line, $m, 0, $i)) return null;
                $segs[] = $m[0];
                $i += strlen($m[0]);
            }
            while ($i < $n && ($line[$i] === ' ' || $line[$i] === "\t")) $i++;
            if ($i >= $n) return null;
            if ($line[$i] === '.') { $i++; continue; }
            if ($line[$i] !== ']') return null;
            $i++;
            if ($arr) { if ($i >= $n || $line[$i] !== ']') return null; $i++; }
            break;
        }
        $rest = trim(substr($line, $i));
        if ($rest !== '' && $rest[0] !== '#') return null;
        return [$segs, $arr];
    }

    /**
     * Split TOML text into tables. A comment line right above a header belongs to that table.
     * @return list<array{segs:?string[],arr:bool,text:string}>
     */
    private static function splitTables(string $body): array {
        $tables = [];
        $cur = ['segs' => null, 'arr' => false, 'lines' => []];
        foreach (explode("\n", $body) as $line) {
            $h = self::headerInfo($line);
            if ($h !== null) {
                $carry = [];
                while (!empty($cur['lines']) && strpos(ltrim((string)end($cur['lines'])), '#') === 0) {
                    array_unshift($carry, array_pop($cur['lines']));
                }
                $tables[] = $cur;
                $cur = ['segs' => $h[0], 'arr' => $h[1], 'lines' => array_merge($carry, [$line])];
            } else {
                $cur['lines'][] = $line;
            }
        }
        $tables[] = $cur;
        $out = [];
        foreach ($tables as $t) {
            $text = rtrim(implode("\n", $t['lines']));
            if (trim($text) === '') continue;
            $out[] = ['segs' => $t['segs'], 'arr' => $t['arr'], 'text' => $text];
        }
        return $out;
    }

    /** 'managed' (server table, its env or headers table), 'server_sub' (any other table below a server) or 'foreign'. */
    private function classify(array $t): string {
        $s = $t['segs'];
        // Keys above the first table header in the fence are a hand edit of the managed block, not a table
        // that Codex added. They count for drift (#799 masking tests) and are never moved out of the fence.
        if ($s === null) return 'managed';
        if ($t['arr'] || ($s[0] ?? '') !== 'mcp_servers' || count($s) < 2) return 'foreign';
        if (count($s) === 2) return 'managed';
        if (count($s) === 3 && in_array($s[2], ['env', $this->headersKey()], true)) return 'managed';
        return 'server_sub';
    }

    /** Fence text that holds only the tables the hub writes. A clean fence comes back byte for byte. */
    private function managedView(string $block, string $open, string $close): string {
        $body = substr($block, strlen($open) + 1, -strlen($close) - 1);
        $keep = [];
        foreach (self::splitTables($body) as $t) {
            if ($this->classify($t) === 'managed') $keep[] = $t['text'];
        }
        return $open . "\n" . implode("\n\n", $keep) . "\n" . $close;
    }

    /** Text with the tool approval tables of any server removed (used by the clash check). */
    private static function withoutToolTables(string $text): string {
        $keep = [];
        foreach (self::splitTables($text) as $t) {
            $s = $t['segs'];
            if ($s !== null && !$t['arr'] && ($s[0] ?? '') === 'mcp_servers' && count($s) >= 3 && $s[2] === 'tools') continue;
            $keep[] = $t['text'];
        }
        return implode("\n\n", $keep);
    }

    /**
     * Take the tables Codex wrote into the current fence.
     * Tables below a managed server (tool approvals) go back into the new block, right after that
     * server. Tables of a server that is no longer wanted are dropped. Every other table
     * (projects, tui, ...) is returned as text for the place after the close marker.
     * @return array{0:string,1:string} [new block, text for after the close marker]
     */
    private function keepForeignTables(string $raw, string $block): array {
        $old = self::extractFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        if ($old === null) return [$block, ''];
        $body = substr($old, strlen(self::FENCE_OPEN) + 1, -strlen(self::FENCE_CLOSE) - 1);
        $subs = []; $after = [];
        foreach (self::splitTables($body) as $t) {
            $c = $this->classify($t);
            if ($c === 'managed') continue;
            if ($c === 'server_sub') { $subs[(string)$t['segs'][1]][] = $t['text']; continue; }
            $after[] = $t['text'];
        }
        if ($block !== '' && !empty($subs)) {
            $inner = substr($block, strlen(self::FENCE_OPEN) + 1, -strlen(self::FENCE_CLOSE) - 1);
            $tables = self::splitTables($inner);
            $parts = [];
            foreach ($tables as $i => $t) {
                $parts[] = $t['text'];
                $name = (string)($t['segs'][1] ?? '');
                $next = $tables[$i + 1]['segs'][1] ?? null;
                if ($next !== $name && isset($subs[$name])) {
                    foreach ($subs[$name] as $sub) $parts[] = $sub;
                    unset($subs[$name]);
                }
            }
            $block = self::FENCE_OPEN . "\n" . implode("\n\n", $parts) . "\n" . self::FENCE_CLOSE;
        }
        return [$block, implode("\n\n", $after)];
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
