#!/bin/bash
# AI CLI Agents: Global Shell Integration (Aliases & Path)
# This file is automatically sourced by bash/sh for all users.
#
# Host-CLI contract: aliases ALWAYS invoke plugin bin proxies under
# /usr/local/emhttp/plugins/unraid-aicliagents/bin/<cmd> (created by
# runtime.sh from AgentRegistry::getShellProxyMap()). Never point aliases
# at raw node_modules / captive-home package paths — those drift from the
# registry binary fields and break after vendor layout changes.

PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"
PLUGIN_BIN="$PLUGIN_ROOT/bin"

# 1. Update PATH to include plugin binaries (Node, fd, rg, agent proxies)
export PATH="$PLUGIN_BIN:$PATH"

# 2. Helper: redirect HOME into the plugin's persistent overlay for this user
# so auth tokens / history bake to Flash with the rest of the managed home.
_aicli_run() {
    local cmd="$1"
    shift
    local user_home="/tmp/unraid-aicliagents/work/$(whoami)/home"
    [ ! -d "$user_home" ] && mkdir -p "$user_home" && chmod 0700 "$user_home" >/dev/null 2>&1
    # Pre-create keyring dir so it lands in the first bake cycle (Bug #1042:
    # if the daemon creates it only at D-Bus start it may not exist on flash
    # before a reboot, causing the auth token to be lost).
    mkdir -p "$user_home/.local/share/aicli-keyring" 2>/dev/null

    local wrapper="$PLUGIN_BIN/$cmd"
    if [ ! -x "$wrapper" ]; then
        echo "AICliAgents: '$cmd' proxy not found at $wrapper — is the plugin installed?" >&2
        return 127
    fi
    HOME="$user_home" "$wrapper" "$@"
}

# 3. Aliases — command names must match AgentRegistry shell_cmds / runtime proxies
alias gemini='_aicli_run gemini'
alias claude='_aicli_run claude'
alias opencode='_aicli_run opencode'
alias kilo='_aicli_run kilo'
alias pi='_aicli_run pi'
alias copilot='_aicli_run copilot'
alias codex='_aicli_run codex'
alias droid='_aicli_run droid'
alias nanocoder='_aicli_run nanocoder'
alias goose='_aicli_run goose'
alias qwen='_aicli_run qwen'
alias agy='_aicli_run agy'
alias grok='_aicli_run grok'
alias kimi='_aicli_run kimi'
alias agent='_aicli_run agent'
alias cursor-agent='_aicli_run cursor-agent'

# Note: data written under the redirected HOME is baked to Flash by the
# plugin supervisor (SquashFS layers + ZRAM upper), not a legacy sync-daemon.
