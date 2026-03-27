#!/bin/bash
# AI CLI Agents: Global Shell Integration (Aliases & Path)
# This file is automatically sourced by bash/sh for all users.

# 1. Update PATH to include plugin binaries (Node, fd, rg)
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"

# 2. Helper for agents to use the plugin's persistent home redirect
# This ensures history and config are saved to the persistent store on Flash.
_aicli_run() {
    local agent_path="$1"
    shift
    # 1. Automatically map to the plugin's persistent RAM home for the current user
    local user_home="/tmp/unraid-aicliagents/work/$(whoami)/home"
    [ ! -d "$user_home" ] && mkdir -p "$user_home" && chmod 0700 "$user_home" >/dev/null 2>&1
    
    # 2. Run agent with redirected HOME (No cleanup or permission logic to avoid interference)
    HOME="$user_home" "$agent_path" "$@"
}


# 3. Aliases for common agents
alias claude='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/claude-code/node_modules/.bin/claude'
alias opencode='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/opencode/node_modules/.bin/opencode'
alias gemini='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/gemini-cli/node_modules/.bin/gemini'
alias kilo='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/kilocode/node_modules/.bin/kilo'
alias pi='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/pi-coder/node_modules/.bin/pi'
alias codex='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/codex-cli/node_modules/.bin/codex'
alias droid='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/factory-cli/node_modules/.bin/droid'
alias nanocoder='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/nanocoder/node_modules/.bin/nanocoder'

# Note: Any commands run via these aliases will have their data automatically
# backed up to Flash by the plugin's background sync daemon.
