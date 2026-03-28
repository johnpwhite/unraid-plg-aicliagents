# AICliAgents Manager: Multi-CLI Plan (Updated v1.5)

## Architectural Evolution
Transitioning from a single-agent wrapper to a generalized **"AICliAgents Manager"**. This design prioritizes a unified workspace experience where multiple different AI agents can coexist within the same filesystem paths. To ensure stability, we will implement the full architecture using **AICliAgents CLI** as the primary, battle-tested agent before expanding to others.

### 1. Dynamic Agent Registry (The "App Store" Model)
The manifest (`agents.json`) defines how each agent is installed, displayed, and executed.

*   **Structure**:
    ```json
    {
      "last_updated": "2026-03-02",
      "agents": {
        "aicli-cli": {
          "id": "aicli-cli",
          "name": "AICliAgents CLI",
          "icon_url": "/plugins/unraid-aicliagents/assets/icons/aicli.png",
          "runtime": "node",
          "install_type": "standalone",
          "url": "https://github.com/google-gemini/gemini-cli/releases/download/v0.31.0/aicli.js",
          "binary": "aicli",
          "resume_cmd": "aicli --resume {chatId}",
          "env_prefix": "GEMINI"
        }
      }
    }
    ```

### 2. UI/UX: Unified Vertical Drawer & Branded Icons
We are replacing the generic folder icon in the drawer with the **Agent's Logo**.

*   **Workspace List**: Each entry in the drawer represents a unique session. 
    *   *Display:* `[Agent Icon] [Workspace Name] (Session ID)`
*   **Multi-Agent Workspaces**: Users can open multiple tabs for the **same path** using different agents (or the same agent).
*   **New Session Modal**: Includes an **Agent Selector** with icons. For Phase 1, only AICli will be available.

### 3. Backend Evolution (`aicli-shell.sh`)
The shell wrapper becomes a generic execution engine driven by the agent's manifest.

*   **Execution Loop**:
    ```bash
    while true; do
        # Export keys from vault based on $ENV_PREFIX
        export "${ENV_PREFIX}_API_KEY"=$(cat /boot/config/plugins/unraid-aicliagents/secrets.cfg | grep "${ENV_PREFIX}_API_KEY" | cut -d'=' -f2)
        
        # Execute binary with resume logic
        if [ -n "$CHAT_ID" ]; then
            eval "${RESUME_CMD//\{chatId\}/$CHAT_ID}"
        else
            eval "$BINARY"
        fi
        read -t 3 -r
    done
    ```

### 4. Surgical Rename & Refactoring Checklist
**MANDATORY**: All "AICli" branding in the plugin structure must be replaced with `AICliAgents`.

- [ ] Rename plugin root to `unraid-plg-aicliagents`.
- [ ] Rename `.plg` and `.xml` to `unraid-aicliagents`.
- [ ] UI: Update `AICliTerminal.tsx` to `AICliAgentsTerminal.tsx`.
- [ ] UI: Replace all CSS `.gemini-*` classes with `.gemini-*`.
- [ ] PHP: Rename `AICliAgentsManager.php` to `AICliAgentsManager.php`.
- [ ] Shell: Rename `aicli-shell.sh` to `aicli-shell.sh`.
- [ ] Paths: Update all `/plugins/unraid-aicliagents/` to `/plugins/unraid-aicliagents/`.

### 5. Implementation Phases (AICli-First Approach)

#### Phase 1: Brand Migration & Foundation
*   Execute the "Surgical Rename" across the entire codebase.
*   Update all internal paths, constants, and CSS classes.
*   Ensure the existing AICliAgents CLI functionality works perfectly under the new `AICliAgents` name.

#### Phase 2: Unified Drawer & Branded Icons
*   Update the React UI to support the new drawer layout.
*   Replace folder icons with agent icons (starting with AICli).
*   Enable the ability to open multiple sessions for the same filesystem path.

#### Phase 3: Architectural Generalization
*   Implement the `agents.json` local registry.
*   Refactor the PHP/Shell backend to be "manifest-driven" rather than hardcoding AICli.
*   Add the "Agent Selector" to the New Workspace modal.

#### Phase 4: Expansion & Runtime Management
*   Implement the portable runtime manager (Python/Static binaries).
*   Add Claude Code, Aider, and other agents to the manifest.
*   Implement the "Secrets Vault" UI in Settings.
