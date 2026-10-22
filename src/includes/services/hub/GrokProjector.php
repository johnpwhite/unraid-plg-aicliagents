<?php
/** Grok Build MCP projection into ~/.grok/config.toml. */
namespace AICliAgents\Services\Hub;

class GrokProjector extends CodexProjector {
    public function agentId(): string { return 'grok-build'; }
    public function relPath(): string { return '.grok/config.toml'; }
    public function label(): string   { return 'Grok Build'; }
    /** Grok Build reads `headers`, not Codex's `http_headers`. */
    protected function headersKey(): string { return 'headers'; }
    /** Grok Build has no `shell_environment_policy` table (#688 is a Codex setting). */
    protected function envPolicyBody(): ?string { return null; }
}
