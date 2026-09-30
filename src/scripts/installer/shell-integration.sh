#!/bin/bash
# AI CLI Agents: Global Shell Integration (Aliases & Path)
# This file is automatically sourced by bash/sh for all users.

# 1. Update PATH to include plugin binaries (Node, fd, rg)
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"

# 1b. GitHub #12: restore the AICliAgents-managed session environment.
# Unraid's /etc/profile always sources every /etc/profile.d/*.sh script LAST,
# after it has already reset HOME, PATH and cwd to host login defaults
# (export HOME=/root; cd $HOME; PATH=/usr/local/bin:...). A Bash LOGIN shell
# started inside an agent session — an agent's own shell tool running
# `bash -lc`, or ssh-attach.sh's `bash --login` fallback — re-runs
# /etc/profile and loses the session's managed HOME/PATH/working directory.
# HOME_DIR, AICLI_MANAGED_PATH, AICLI_CWD and AICLI_WORKSPACE_PATH are
# exported by the session's own RUN_SCRIPT (aicli-shell.sh) and are never
# touched by /etc/profile, so this restores from them. Guarded on HOME_DIR
# so a normal admin login (no such session) is completely unaffected.
if [ -n "$HOME_DIR" ]; then
    export HOME="$HOME_DIR"
    if [ -n "$AICLI_MANAGED_PATH" ]; then
        export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$AICLI_MANAGED_PATH"
    fi
    if [ -n "$AICLI_CWD" ] && [ -d "$AICLI_CWD" ]; then
        cd "$AICLI_CWD" 2>/dev/null || true
    elif [ -n "$AICLI_WORKSPACE_PATH" ] && [ -d "$AICLI_WORKSPACE_PATH" ]; then
        cd "$AICLI_WORKSPACE_PATH" 2>/dev/null || true
    fi
fi

# 2. Helper for agents to use the plugin's persistent home redirect
# This ensures history and config are saved to the persistent store on Flash.
_aicli_run() {
    local agent_path="$1"
    shift
    # 1. Automatically map to the plugin's persistent RAM home for the current user
    local user_home="/tmp/unraid-aicliagents/work/$(whoami)/home"
    [ ! -d "$user_home" ] && mkdir -p "$user_home" && chmod 0700 "$user_home" >/dev/null 2>&1
    # Pre-create keyring dir so it lands in the first bake cycle (Bug #1042:
    # if the daemon creates it only at D-Bus start it may not exist on flash
    # before a reboot, causing the auth token to be lost).
    mkdir -p "$user_home/.local/share/aicli-keyring" 2>/dev/null

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
alias copilot='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/bin/copilot'
alias goose='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/goose/bin/goose'
alias qwen='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/qwen-code/node_modules/.bin/qwen'
alias agy='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/antigravity-cli/home/.local/bin/agy'
alias grok='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/grok-build/home/.grok/bin/grok'
alias kimi='_aicli_run /usr/local/emhttp/plugins/unraid-aicliagents/agents/kimi-code/home/.kimi-code/bin/kimi'

# Note: Any commands run via these aliases will have their data automatically
# backed up to Flash by the plugin's background sync daemon.
