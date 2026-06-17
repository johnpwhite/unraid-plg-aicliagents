<?php
/**
 * <module_context>
 *     <name>Transpiler</name>
 *     <description>Pure canonical-MCP → vendor-format transpilers (OP #1362 / H-01):
 *     toCodexToml() emits [mcp_servers.&lt;id&gt;] TOML tables; toGooseYaml() emits
 *     Goose extensions: map entries (key is `cmd`, NOT `command`; two-space indent).
 *     No external deps, no I/O. Deterministic output (sorted server names, sorted
 *     keys) so projection hashes are stable across runs.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Pure static functions only — fences are the projectors' concern,
 *     this emits bare config text. Never logs (values may be secrets).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class Transpiler {

    /**
     * Canonical servers → Codex CLI TOML ([mcp_servers.<id>] tables).
     * @param array<string,array> $servers name => normalized canonical def
     *        (env placeholders already resolved by the caller).
     */
    public static function toCodexToml(array $servers): string {
        ksort($servers);
        $blocks = [];
        foreach ($servers as $name => $def) {
            $lines = ['[mcp_servers.' . $name . ']'];
            if (($def['transport'] ?? 'stdio') === 'stdio') {
                $lines[] = 'command = ' . self::tomlString((string)($def['command'] ?? ''));
                $args = $def['args'] ?? [];
                if (!empty($args)) {
                    $lines[] = 'args = [' . implode(', ', array_map([self::class, 'tomlString'], $args)) . ']';
                }
                $env = $def['env'] ?? [];
                if (!empty($env)) {
                    ksort($env);
                    $lines[] = '';
                    $lines[] = '[mcp_servers.' . $name . '.env]';
                    foreach ($env as $k => $v) {
                        $lines[] = self::tomlKey((string)$k) . ' = ' . self::tomlString((string)$v);
                    }
                }
            } else {
                $lines[] = 'url = ' . self::tomlString((string)($def['url'] ?? ''));
            }
            $blocks[] = implode("\n", $lines);
        }
        return implode("\n\n", $blocks);
    }

    /**
     * Canonical servers → Goose config.yaml `extensions:` map entries.
     * Emitted at $indent spaces (children of the extensions: key — default 2).
     * Goose stdio key is `cmd` (not `command`); env map key is `envs`;
     * remote transports use `uri` with type sse / streamable_http.
     * @param array<string,array> $servers name => normalized canonical def.
     */
    public static function toGooseYaml(array $servers, int $indent = 2): string {
        ksort($servers);
        $i1 = str_repeat(' ', $indent);
        $i2 = str_repeat(' ', $indent + 2);
        $i3 = str_repeat(' ', $indent + 4);
        $out = [];
        foreach ($servers as $name => $def) {
            $transport = $def['transport'] ?? 'stdio';
            $out[] = $i1 . self::yamlKey((string)$name) . ':';
            if ($transport === 'stdio') {
                $args = $def['args'] ?? [];
                if (!empty($args)) {
                    $out[] = $i2 . 'args:';
                    foreach ($args as $a) $out[] = $i2 . '- ' . self::yamlScalar((string)$a);
                } else {
                    $out[] = $i2 . 'args: []';
                }
                $out[] = $i2 . 'cmd: ' . self::yamlScalar((string)($def['command'] ?? ''));
            }
            $out[] = $i2 . 'enabled: true';
            if ($transport === 'stdio') {
                $env = $def['env'] ?? [];
                if (!empty($env)) {
                    ksort($env);
                    $out[] = $i2 . 'envs:';
                    foreach ($env as $k => $v) {
                        $out[] = $i3 . self::yamlKey((string)$k) . ': ' . self::yamlScalar((string)$v);
                    }
                }
            }
            $out[] = $i2 . 'name: ' . self::yamlScalar((string)$name);
            $out[] = $i2 . 'timeout: 300';
            if ($transport === 'stdio') {
                $out[] = $i2 . 'type: stdio';
            } else {
                $out[] = $i2 . 'type: ' . ($transport === 'http' ? 'streamable_http' : 'sse');
                $out[] = $i2 . 'uri: ' . self::yamlScalar((string)($def['url'] ?? ''));
            }
        }
        return implode("\n", $out);
    }

    // ---------- TOML quoting ----------

    /** Basic TOML string: double-quoted, backslash escapes, control chars as \uXXXX. */
    public static function tomlString(string $v): string {
        $out = '';
        $len = strlen($v);
        for ($i = 0; $i < $len; $i++) {
            $c = $v[$i];
            $ord = ord($c);
            if ($c === '\\')      $out .= '\\\\';
            elseif ($c === '"')   $out .= '\\"';
            elseif ($c === "\n")  $out .= '\\n';
            elseif ($c === "\r")  $out .= '\\r';
            elseif ($c === "\t")  $out .= '\\t';
            elseif ($ord < 0x20)  $out .= sprintf('\\u%04X', $ord);
            else                  $out .= $c;
        }
        return '"' . $out . '"';
    }

    /** TOML key: bare if [A-Za-z0-9_-]+, else quoted. */
    public static function tomlKey(string $k): string {
        return preg_match('/^[A-Za-z0-9_-]+$/', $k) ? $k : self::tomlString($k);
    }

    // ---------- YAML quoting ----------

    /**
     * YAML scalar: bare when unambiguous; otherwise single-quoted (embedded
     * single quotes doubled); double-quoted with escapes when control chars
     * are present. Deterministic.
     */
    public static function yamlScalar(string $v): string {
        if ($v === '') return "''";
        if (preg_match('/[\x00-\x1F]/', $v)) {
            // control chars → double-quoted style
            $out = '';
            $len = strlen($v);
            for ($i = 0; $i < $len; $i++) {
                $c = $v[$i];
                $ord = ord($c);
                if ($c === '\\')     $out .= '\\\\';
                elseif ($c === '"')  $out .= '\\"';
                elseif ($c === "\n") $out .= '\\n';
                elseif ($c === "\t") $out .= '\\t';
                elseif ($c === "\r") $out .= '\\r';
                elseif ($ord < 0x20) $out .= sprintf('\\x%02X', $ord);
                else                 $out .= $c;
            }
            return '"' . $out . '"';
        }
        // Bare-safe: no YAML indicators, not number/bool/null-like, no leading/trailing
        // space. A leading '-' is fine for flag-style args ('--port') but a bare '-'
        // or '- x' would read as a sequence indicator → quoted.
        $boolNullNum = '/^(?:true|false|yes|no|on|off|null|~|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?|0x[0-9a-fA-F]+)$/i';
        if (preg_match('#^[A-Za-z0-9_./@+-][A-Za-z0-9_./@+ -]*$#', $v)
            && !preg_match('/^-( |$)/', $v)
            && !preg_match($boolNullNum, $v)
            && $v === trim($v)
            && strpos($v, ' #') === false) {
            return $v;
        }
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** YAML map key: bare if simple, else single-quoted. */
    public static function yamlKey(string $k): string {
        return preg_match('/^[A-Za-z0-9_-]+$/', $k) ? $k : "'" . str_replace("'", "''", $k) . "'";
    }
}
