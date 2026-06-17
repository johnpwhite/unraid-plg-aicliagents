<?php
/**
 * <module_context>
 *     <name>GooseProjector</name>
 *     <description>Goose MCP projection into ~/.config/goose/config.yaml (OP #1362 / H-01).
 *     YAML vendor: managed entries are emitted inside comment fences nested under the
 *     top-level `extensions:` map at two-space indent (Goose key is `cmd`, not
 *     `command`). If the file or the extensions: key is absent, it is created
 *     minimally. Keys outside the fence are never touched. Drift hashes the full
 *     fence content (single managed key per file).</description>
 *     <dependencies>VendorProjector, CodexProjector (fence helpers), Transpiler</dependencies>
 *     <constraints>Idempotent: projecting twice yields a byte-identical file.
 *     Goose credentials live in the OS keyring — never touched here.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class GooseProjector extends VendorProjector {

    const FENCE_KEY   = 'aicli-hub-fence';
    const FENCE_OPEN  = '  # >>> aicli-hub managed — do not edit inside >>>';
    const FENCE_CLOSE = '  # <<< aicli-hub <<<';

    public function agentId(): string { return 'goose'; }
    public function relPath(): string { return '.config/goose/config.yaml'; }
    public function label(): string   { return 'Goose'; }

    public function desired(array $servers): array {
        if (empty($servers)) return [];
        return [self::FENCE_KEY => self::FENCE_OPEN . "\n" . Transpiler::toGooseYaml($servers, 2) . "\n" . self::FENCE_CLOSE];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        $block = CodexProjector::extractFence((string)@file_get_contents($file), self::FENCE_OPEN, self::FENCE_CLOSE);
        return $block === null ? [] : [self::FENCE_KEY => $block];
    }

    public function write(string $file, array $set, array $remove): bool {
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        if (array_key_exists(self::FENCE_KEY, $set)) {
            $raw = self::placeFence($raw, (string)$set[self::FENCE_KEY]);
        } elseif (in_array(self::FENCE_KEY, $remove, true)) {
            $raw = CodexProjector::stripFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        } else {
            return true;
        }
        return $this->atomicWrite($file, $raw);
    }

    /**
     * Insert/replace the fenced block directly under the top-level `extensions:`
     * key (so the indented entries are valid children). Creates the file or the
     * extensions: key minimally when absent. `extensions: {}` is rewritten to a
     * block map so the fence can nest (the only structural edit ever made).
     */
    public static function placeFence(string $raw, string $block): string {
        // 1. Existing fence anywhere → replace in place (idempotent re-run).
        $re = '/^' . preg_quote(self::FENCE_OPEN, '/') . '\n.*?^' . preg_quote(self::FENCE_CLOSE, '/') . '$/ms';
        if (preg_match($re, $raw)) {
            return (string)preg_replace($re, strtr($block, ['\\' => '\\\\', '$' => '\\$']), $raw, 1);
        }
        // 2. Empty file → minimal scaffold.
        if (trim($raw) === '') {
            return "extensions:\n" . $block . "\n";
        }
        // 3. Top-level extensions: key (block map or empty flow map) → nest under it.
        if (preg_match('/^extensions:[ \t]*(\{\s*\})?[ \t]*$/m', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $lineStart = $m[0][1];
            $lineLen = strlen($m[0][0]);
            return substr($raw, 0, $lineStart) . 'extensions:' . "\n" . $block . substr($raw, $lineStart + $lineLen);
        }
        // 4. No extensions key → append one at EOF.
        return rtrim($raw, "\n") . "\n\nextensions:\n" . $block . "\n";
    }
}
