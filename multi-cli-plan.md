# Coding Agents Manager: Multi-CLI Plan

## Architectural Evolution
Transitioning from a single-agent wrapper to a generalized **"Coding Agents Manager"** turns the plugin into a highly valuable platform. Here is the comprehensive design proposal to achieve this dynamic installation, version management, and UI updates without needing to push new `.plg` updates.

### 1. Dynamic Agent Registry (The "App Store" Model)
To ensure agents can be added or updated without updating the plugin itself, we implement a remote manifest architecture.

*   **The Manifest (`agents.json`)**: You host a simple JSON file on a public URL (e.g., GitHub Pages, Gist, or your GitLab). This file acts as the source of truth.
*   **Structure**:
    ```json
    {
      "last_updated": "2026-02-27",
      "agents": {
        "gemini-cli": {
          "name": "Gemini CLI",
          "description": "Google's official command line agent.",
          "version": "v0.30.0",
          "url": "https://github.com/google-gemini/gemini-cli/releases/download/v0.30.0/gemini.js",
          "type": "node",
          "run_cmd": "gemini"
        },
        "claude-code": {
          "name": "Claude Code",
          "description": "Anthropic's interactive CLI tool.",
          "version": "v0.1.0",
          "url": "npm:@anthropic-ai/claude-code@latest",
          "type": "npm",
          "run_cmd": "claude"
        }
      }
    }
    ```
*   **Mechanism**: Every time the user visits the Settings page, PHP fetches this JSON (with a short cache to prevent rate-limiting). This allows you to add new agents or bump versions purely by editing the remote JSON file.

### 2. Plugin Installer (`.plg`) Overhaul
*   **Strips Out Agents**: The `.plg` file will no longer download `gemini.js`.
*   **Core Dependencies Only**: It will strictly download and install the universal engine:
    *   Portable Node.js / npm (for running JS agents).
    *   Portable `tmux` (for session persistence).
    *   `ttyd` (if not relying on Unraid's core).
*   **Initial State**: Upon first boot, the "CODING AGENTS" tab will show a welcome screen: *"No agents installed. Please visit Settings to install your first agent."*

### 3. Settings Page UI / UX (Agent Management)
The settings page becomes the control center.

*   **Agent Listing**: Reads the fetched `agents.json` and lists them as cards. It compares the `version` in the JSON against a locally stored `installed_agents.json` to determine state.
*   **Buttons**: `[ Install ]`, `[ Update Available ]`, `[ Uninstall ]`.
*   **The SweetAlert Flow (Security & Transparency)**:
    *   When the user clicks "Install" for an agent, a native Unraid `swal` triggers.
    *   *UI:* "You are about to install **Gemini CLI**."
    *   *Hyperlink:* `<a href="https://github.com/..." target="_blank">View Source / Repository</a>`
    *   *Prompt:* "Are you sure you want to download and execute this binary?"
    *   *Action:* Upon confirmation, an AJAX call triggers a background PHP script to download/npm-install the agent into the plugin's RAM/Boot directory. A progress spinner shows until complete.

### 4. Main Tab UI ("CODING AGENTS")
The React component is updated to handle a multi-agent reality.

*   **Sub-Tabs for Agents**: The top-level tab is "CODING AGENTS". The React UI's first layer of navigation will be tabs for each *installed* agent (e.g., `[ Gemini ] [ Claude ] [ Aider ]`).
*   **Workspace Tabs**: Beneath the Agent tab, the user sees their active workspaces for *that specific agent*.
*   **"New Workspace" Dialogue Expansion**:
    *   When clicking "New Workspace", the modal now includes a dropdown: **"Select Agent: [ Gemini CLI v ]"**.
    *   It defaults to the agent tab they are currently viewing.

### 5. Backend Execution (`agent-shell.sh`)
The `gemini-shell.sh` is generalized into `agent-shell.sh`.
*   Instead of hardcoding the execution command, the PHP backend passes the `run_cmd` (from the manifest) as an environment variable (`$AGENT_CMD`) when spinning up the `tmux` session.
*   The script dynamically creates the run loop:
    ```bash
    while true; do
        $AGENT_CMD
        echo "Agent exited. Press ENTER to restart..."
        read -r
    done
    ```

### 6. Extra Feature Recommendations for UX
1.  **Centralized API Key Vault**: Provide a "Vault" in the settings for API keys (`OPENAI_API_KEY`, etc.), saved securely to `/boot/config/plugins/.../secrets.cfg`. The shell wrapper automatically exports these into the `tmux` environment.
2.  **"Bring Your Own Agent" (BYOA)**: Allow advanced users to add unlisted agents by providing an NPM package name or raw URL and execution command.
3.  **Global "Kill Switch"**: A button in settings to force-kill all `tmux` and agent processes across all workspaces instantly.

---

## Refactoring & Renaming Commentary

To fully transition to this new architecture, the current codebase must be systematically scrubbed of its "Gemini" specific branding to reflect its new, generic "Coding Agents Manager" nature. This ensures clarity for both users and future development.

**Required Renaming Actions:**

*   **Repository Name**: 
    *   Rename the source repository from `unraid-plg-geminicli` to something like `unraid-plg-coding-agents` or `unraid-plg-ai-agents`.
*   **Plugin Configuration Files**: 
    *   Rename `unraid-geminicli.plg` to `unraid-coding-agents.plg`.
    *   Rename `unraid-geminicli.xml` to `unraid-coding-agents.xml`.
*   **Source Files (.page, .php, .sh)**: 
    *   `GeminiCLI.page` -> `CodingAgents.page`
    *   `GeminiSettings.page` -> `AgentSettings.page`
    *   `includes/GeminiSettings.php` -> `includes/AgentSettings.php`
    *   `scripts/gemini-shell.sh` -> `scripts/agent-shell.sh`
*   **CSS and DOM Targeting**:
    *   Update all React components and CSS files to remove specific classes and IDs.
    *   Change `#gemini-cli-root` to `#agent-manager-root`.
    *   Change CSS prefix `.gemini-swal` to `.agent-swal`.
*   **Environment Variables**:
    *   Change variables within the shell wrappers from `GEMINI_HOME`, `GEMINI_USER`, `GEMINI_ROOT` to generic names like `AGENT_HOME`, `AGENT_USER`, `WORKSPACE_ROOT`.
*   **Storage and Paths**:
    *   Update the installer script (`.plg`) to use the new plugin directory: `/boot/config/plugins/unraid-coding-agents/` and `/usr/local/emhttp/plugins/unraid-coding-agents/`. 
    *   Update all hardcoded `fetch` URLs in the React UI and PHP includes to point to the new `/plugins/unraid-coding-agents/` path.