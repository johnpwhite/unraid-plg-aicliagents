# AI CLI Agents: Agent Integration Specification

This document defines the requirements and standard procedure for adding or maintaining AI agents within the Unraid AICliAgents ecosystem.

## 1. Registry Definition (`AICliAgentsManager.php`)

Every agent must be defined in `getAICliAgentsRegistry()`. The definition must include:

| Key | Description | Example |
| :--- | :--- | :--- |
| `id` | Unique slug for the agent. | `my-agent` |
| `name` | Human-readable name. | `My Agent CLI` |
| `icon_url` | Path to icon (store in `assets/icons/`). | `/plugins/.../my-agent.png` |
| `runtime` | The language runtime required. | `node` |
| `binary` | Absolute path to the executable in RAM. | `"$agentBase/my-agent/node_modules/.bin/my-bin"` |
| `resume_cmd` | Command to resume a specific session. | `"my-bin --session {chatId}"` |
| `resume_latest` | Command to continue the last session. | `"my-bin --continue"` |
| `env_prefix` | Prefix for agent-specific ENV variables. | `MYAGENT` |

## 2. NPM Mapping (`AICliAgentsManager.php`)

If the agent is an NPM package, add it to `getAICliNpmMap()`:
```php
'my-agent' => '@scope/my-agent-package'
```

## 3. Auto-Update Prevention (MANDATORY)

To ensure stability and user-controlled updates, **all agents must have auto-updates disabled at the runtime level.**

### Implementation in `src/scripts/aicli-shell.sh`:
Add the agent's specific "disable update" environment variable to the `D-59` block in `aicli-shell.sh`.

**Common Flags:**
- `DISABLE_AUTOUPDATER=1` (Universal for many AI tools)
- `GEMINI_CLI_DISABLE_AUTO_UPDATE=true`
- `OPENCODE_DISABLE_AUTOUPDATE=true`
- `DISABLE_UPDATE_CHECK=1`

## 4. Installation & Lifecycle

The plugin handles installation via `installAgent($agentId)`:
1.  **Staging**: Installs to `/tmp/aicli-install-<id>` via `npm install --prefix`.
2.  **Caching**: Creates a `.tar.gz` in `/boot/config/plugins/unraid-aicliagents/pkg-cache/`.
3.  **Deployment**: Unpacks to `/usr/local/emhttp/plugins/unraid-aicliagents/agents/<id>`.
4.  **Permissions**: Recursively sets `chmod 755` on the agent directory.

## 5. Isolation & Persistence

- **Environment**: Agents run with `HOME` redirected to RAM (`/tmp/unraid-aicliagents/work/<user>/home`).
- **Sync**: User data (configs/chats) are asynchronously mirrored to USB via the background sync daemon.
- **Exclusions**: Large caches (`.npm`, `.bun`, `node_modules`) and Unix sockets are excluded from USB sync to protect the flash drive.

---
*Last Updated: 2026.03.30 (D-59 compliance)*
