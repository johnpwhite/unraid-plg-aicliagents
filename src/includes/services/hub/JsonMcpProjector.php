<?php
/**
 * <module_context>
 *     <name>JsonMcpProjector</name>
 *     <description>Shared base for the five mcpServers-JSON vendors (Claude, Gemini,
 *     Qwen, OpenCode, Copilot). Read-modify-write of ONLY mcpServers.&lt;managedName&gt;
 *     keys. Decodes with assoc=false (stdClass) so empty objects elsewhere in the
 *     file ({} vs []) survive the round-trip — critical for ~/.claude.json which
 *     co-locates OAuth state with settings. Encodes JSON_PRETTY_PRINT |
 *     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE; whitespace/key order may
 *     normalize on FIRST touch, after which re-projection is byte-stable.</description>
 *     <dependencies>VendorProjector</dependencies>
 *     <constraints>NEVER rewrites an unparseable file (write() returns false).
 *     Subclasses define only vendorValue() — the vendor-shape mapping.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

abstract class JsonMcpProjector extends VendorProjector {

    const TOP_KEY = 'mcpServers';

    /** Top-level object key that holds the server map. Overridden by vendors that
     *  use a different root key (OpenCode/Kilo use "mcp", not "mcpServers"). */
    protected function topKey(): string { return self::TOP_KEY; }

    /** Map one canonical server def → this vendor's server-entry shape. */
    abstract protected function vendorValue(array $def): array;

    /**
     * Object path (from the file root) of the map that holds the servers. Most vendors
     * use one key. OpenCode 2 nests it: mcp.servers (https://opencode.ai/v2/docs/mcp-servers/).
     * @return string[]
     */
    protected function containerPath(): array { return [$this->topKey()]; }

    /** Managed-key prefix: the container path joined with dots, then a dot. */
    private function keyPrefix(): string { return implode('.', $this->containerPath()) . '.'; }

    /**
     * Walk containerPath() below $data. Returns the server map, or null when a node is
     * absent (and $create is false) or is not an object (such a node is never replaced).
     */
    private function container(\stdClass $data, bool $create): ?\stdClass {
        $node = $data;
        foreach ($this->containerPath() as $key) {
            if (!isset($node->{$key})) {
                if (!$create) return null;
                $node->{$key} = new \stdClass();
            }
            if (!($node->{$key} instanceof \stdClass)) return null;
            $node = $node->{$key};
        }
        return $node;
    }

    public function desired(array $servers): array {
        ksort($servers);
        $out = [];
        foreach ($servers as $name => $def) {
            $out[$this->keyPrefix() . $name] = $this->vendorValue($def);
        }
        return $out;
    }

    public function current(string $file, array $keys): array {
        $data = $this->decode($file);
        $map = $data === null ? null : $this->container($data, false);
        if ($map === null) return [];
        $out = [];
        foreach ($keys as $key) {
            $name = $this->serverNameFromKey($key);
            if ($name !== null && property_exists($map, $name)) {
                $out[$key] = self::canonicalize($map->{$name});
            }
        }
        return $out;
    }

    public function write(string $file, array $set, array $remove): bool {
        $data = $this->decode($file);
        if ($data === null) {
            if (is_file($file) && trim((string)@file_get_contents($file)) !== '') {
                return false; // unparseable non-empty file — never clobber
            }
            $data = new \stdClass();
        }
        $map = $this->container($data, true);
        if ($map === null) return false; // user has a non-object value on the path — do not fight it
        foreach ($set as $key => $value) {
            $name = $this->serverNameFromKey($key);
            if ($name === null) continue;
            // assoc array → stdClass tree so {} renders as {} (deterministic key order via canonicalize)
            $map->{$name} = json_decode(json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($remove as $key) {
            $name = $this->serverNameFromKey($key);
            if ($name !== null) unset($map->{$name});
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        return $this->atomicWrite($file, $json . "\n");
    }

    /**
     * The text with // and slash-star comments removed (and trailing commas before } or ]),
     * leaving text inside strings alone. Used only to explain WHY a file is refused (#666 R-159).
     */
    public static function stripJsonComments(string $raw): string {
        $out = '';
        $len = strlen($raw);
        $inStr = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($inStr) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $len) { $out .= $raw[++$i]; }
                elseif ($c === '"') { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; $out .= $c; continue; }
            if ($c === '/' && $i + 1 < $len && $raw[$i + 1] === '/') {
                while ($i < $len && $raw[$i] !== "\n") $i++;
                $out .= "\n";
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $raw[$i + 1] === '*') {
                $end = strpos($raw, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            $out .= $c;
        }
        return (string)preg_replace('/,(\s*[}\]])/', '$1', $out);
    }

    /** 'mcpServers.<name>' → '<name>' (null if not a managed-key shape). */
    public function serverNameFromKey(string $key): ?string {
        $prefix = $this->keyPrefix();
        if (strncmp($key, $prefix, strlen($prefix)) !== 0) return null;
        $name = substr($key, strlen($prefix));
        return preg_match(HubStore::NAME_RE, $name) ? $name : null;
    }

    /** Decode the vendor file as stdClass tree; null on absence or parse failure. */
    private function decode(string $file): ?\stdClass {
        if (!is_file($file)) return null;
        $raw = (string)@file_get_contents($file);
        if (trim($raw) === '') return null;
        $data = json_decode($raw);
        return ($data instanceof \stdClass) ? $data : null;
    }

    // ---------- shared shape helpers for subclasses ----------

    /** De facto mcpServers stdio shape: {command, args?, env?}. */
    protected function stdioShape(array $def): array {
        $v = ['command' => (string)($def['command'] ?? '')];
        if (!empty($def['args'])) $v['args'] = array_values(array_map('strval', $def['args']));
        if (!empty($def['env'])) { $env = $def['env']; ksort($env); $v['env'] = $env; }
        return $v;
    }
}
