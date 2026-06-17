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

    public function desired(array $servers): array {
        ksort($servers);
        $out = [];
        foreach ($servers as $name => $def) {
            $out[$this->topKey() . '.' . $name] = $this->vendorValue($def);
        }
        return $out;
    }

    public function current(string $file, array $keys): array {
        $data = $this->decode($file);
        if ($data === null || !isset($data->{$this->topKey()}) || !($data->{$this->topKey()} instanceof \stdClass)) return [];
        $out = [];
        foreach ($keys as $key) {
            $name = $this->serverNameFromKey($key);
            if ($name !== null && property_exists($data->{$this->topKey()}, $name)) {
                $out[$key] = self::canonicalize($data->{$this->topKey()}->{$name});
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
        if (!isset($data->{$this->topKey()}) || !($data->{$this->topKey()} instanceof \stdClass)) {
            if (isset($data->{$this->topKey()})) return false; // user has a non-object mcpServers — do not fight it
            $data->{$this->topKey()} = new \stdClass();
        }
        foreach ($set as $key => $value) {
            $name = $this->serverNameFromKey($key);
            if ($name === null) continue;
            // assoc array → stdClass tree so {} renders as {} (deterministic key order via canonicalize)
            $data->{$this->topKey()}->{$name} = json_decode(json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($remove as $key) {
            $name = $this->serverNameFromKey($key);
            if ($name !== null) unset($data->{$this->topKey()}->{$name});
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        return $this->atomicWrite($file, $json . "\n");
    }

    /** 'mcpServers.<name>' → '<name>' (null if not a managed-key shape). */
    protected function serverNameFromKey(string $key): ?string {
        $prefix = $this->topKey() . '.';
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
