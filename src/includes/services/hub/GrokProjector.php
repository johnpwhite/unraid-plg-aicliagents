<?php
/** Grok Build MCP projection into ~/.grok/config.toml. */
namespace AICliAgents\Services\Hub;

class GrokProjector extends CodexProjector {
    public function agentId(): string { return 'grok-build'; }
    public function relPath(): string { return '.grok/config.toml'; }
    public function label(): string   { return 'Grok Build'; }
}
