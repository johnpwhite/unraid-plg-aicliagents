# AICliAgents: New Coding Agent Specification

This document outlines the standard procedure for adding a new CLI coding agent to the AICliAgents Unraid plugin.

## Overview
The architecture is designed to be manifest-driven, but currently requires minor updates across the backend (PHP), frontend (React), and shell wrapper to ensure full integration, session isolation, and security.

---

## 1. Backend Integration (`AICliAgentsManager.php`)

### Agent Registry
Add the agent definition to the `$defaultRegistry` array in `getAICliAgentsRegistry()`.

**Fields:**
- `id` (string): Unique identifier (e.g., `my-agent`).
- `name` (string): Display name.
- `icon_url` (string): URL to the agent icon. Standard path: `/plugins/unraid-aicliagents/assets/icons/my-agent.ico`.
- `release_notes` (string): URL to the agent's release notes.
- `runtime` (string): Usually `node`, used for future execution planning.
- `binary` (string): The command to start a fresh session (e.g., `my-agent-cli`).
- `resume_cmd` (string): The command to resume a specific session. Use `{chatId}` as a placeholder for the session identifier.
- `resume_latest` (string): The command to resume the most recent session.
- `env_prefix` (string): The prefix used for the API key in the Secrets Vault (e.g., `MYAGENT`).
- `is_installed` (bool): Logic to detect if the binary is available (e.g., `!empty(shell_exec('which my-agent-cli 2>/dev/null'))`).

### Installation Lifecycle
Update `installAgent($agentId)` and `uninstallAgent($agentId)` with the appropriate commands. 

**Note on RAM-First Installation:**
To prevent flash wear and ensure persistence, we use a "Cache & Unpack" model:
1.  Agents are installed into a temporary RAM directory via NPM.
2.  The resulting `node_modules` and binaries are tarred into a `.tar.gz`.
3.  This tarball is saved to the USB cache: `/boot/config/plugins/unraid-aicliagents/pkg-cache/`.
4.  On every boot, the `.plg` script automatically unpacks these into `/usr/local/emhttp/plugins/unraid-aicliagents/bin/` (RAM).

Ensure the `is_installed` check in `getAICliAgentsRegistry()` points to the RAM path (e.g., `file_exists("$binDir/node_modules/.bin/myagent")`).

---

## 2. Frontend Integration (`AICliAgentsTerminal.tsx`)

### UI Registry
Update the `AGENT_REGISTRY` constant in the React component. This is used for rendering icons in the workspace drawer and the "New Workspace" modal.

```typescript
const AGENT_REGISTRY: Record<string, { name: string, icon: string }> = {
    // ...
    'my-agent': { name: 'My Agent', icon: '/plugins/unraid-aicliagents/assets/icons/my-agent.ico' },
};
```

---

## 3. Shell & Security (`aicli-shell.sh` & `.page`)

### Secrets Vault
Update `AICliAgentsManager.page` to include a password field for the new agent's API key.

```html
<dt>My Agent API Key:</dt>
<dd>
    <input type="password" name="MYAGENT_API_KEY" value="<?=htmlspecialchars($vault['MYAGENT_API_KEY'] ?? '')?>" placeholder="Enter API Key">
</dd>
```

### Environment Injection
The `aicli-shell.sh` script automatically handles API key injection from `secrets.cfg` using the `ENV_PREFIX` defined in the registry. Ensure the `ENV_PREFIX` in the registry matches the name used in the `.page` file (e.g., `MYAGENT` -> `MYAGENT_API_KEY`).

---

## 4. Installer (`unraid-aicliagents.plg`)
Ensure the agent's icon is included in the `<FILE>` download list to ensure it is available offline.

```xml
download_file "src/assets/icons/my-agent.ico" "assets/icons/my-agent.ico"
```

---

## Technical Considerations (The "Why")

### 1. Session Isolation
We use the pattern `aicli-agent-$AGENT_ID-$ID` for tmux sessions. 
- **Why?** This prevents different agents (e.g., Gemini and Kilo) from re-attaching to the same tmux session if they share a workspace path and session ID.

### 2. Environment Freezing
Agent variables (`BINARY`, `RESUME_CMD`, etc.) are captured in `aicli-shell.sh` and injected directly into a generated `/tmp/aicli-run-$ID.sh` script.
- **Why?** `tmux` sessions persist in the background. If a user switches an agent for an existing session ID, we must ensure the *new* agent's environment is used. Freezing these variables at the point of `ttyd` execution ensures the shell loop always runs the correct binary, even if the parent environment changes.

### 3. Iframe Key Rotation
The React `iframe` uses a key combined of `activeId`, `agentId`, and `lastActive`.
- **Why?** This forces React to unmount and remount the iframe when an agent is switched, ensuring the terminal UI reflects the state of the new agent immediately.

### 4. Manifest-Driven Design
While several files currently require updates, the `getAICliAgentsRegistry` function is designed to load from an external `agents.json` if present.
- **Why?** This allows for future "Dynamic Agent" support where users could add custom agents via a JSON manifest without modifying the plugin's core code.
