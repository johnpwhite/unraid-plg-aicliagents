<?php
/**
 * Dedicated rules-file variant of RelayInstructionProjector, for agents that
 * auto-discover a rules directory (Kilo ~/.kilo/rules/aicli-relay.md, Claude
 * ~/.claude/rules/aicli-relay.md). Writes the Relay guidance BODY whole, with no
 * HTML-comment fence — the hub owns the entire file. Keeps the parent's
 * '#aicli-relay-guidance' ledger-key suffix so its bookkeeping stays distinct
 * from the hub-instruction file that shares the same rules directory.
 */

namespace AICliAgents\Services\Hub;

class RulesFileRelayProjector extends RelayInstructionProjector {
    public function desired(array $servers): array {
        return [self::FENCE_KEY => rtrim(self::BODY, "\n") . "\n"];
    }

    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        return [self::FENCE_KEY => (string)@file_get_contents($file)];
    }

    public function write(string $file, array $set, array $remove): bool {
        if (array_key_exists(self::FENCE_KEY, $set)) return $this->atomicWrite($file, (string)$set[self::FENCE_KEY]);
        if (in_array(self::FENCE_KEY, $remove, true)) {
            if (is_file($file) && !@unlink($file)) return false;
            @rmdir(dirname($file));
        }
        return true;
    }
}
