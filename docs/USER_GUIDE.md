# AI CLI Agents: User & Architecture Guide

This guide explains the internal architecture of the AI CLI Agents plugin, focusing on the hybrid persistence system, synchronized scheduling, and user management logic.

## 1. Hybrid Persistence Architecture

To balance performance with the reliability of the Unraid Flash drive (USB), AI CLI Agents uses a dual-layer storage system:

- **RAM Working Volume (`/tmp/unraid-aicliagents/work/<user>`)**: 
  - All active terminal operations, agent binaries, and temporary files run directly from RAM.
  - This prevents excessive wear on your USB Flash drive and ensures zero-latency file operations for AI agents.
- **Flash Persistence Store (`/boot/config/plugins/unraid-aicliagents/persistence/home_<user>.img`)**:
  - This is where your data lives permanently, encapsulated in a high-performance **Btrfs loopback image**.
  - On system boot or plugin installation, this image is efficiently copied from Flash to RAM.
  - Periodic synchronization uses **Btrfs Delta Sync (Send/Receive)**, which only transfers modified blocks between the RAM snapshot and the persistent image. This is significantly faster and safer for SQLite databases than traditional file-level `rsync`.

## 2. Zero-Archive Deployment Logic

To ensure the fastest possible startup and update times, AI CLI Agents uses a "Zero-Archive" architecture:

- **Block-Device Execution**: Agents are installed directly into a persistent Btrfs container (`aicli-agents.img`) on your Flash drive.
- **No Archive Overhead**: Unlike traditional plugins that extract `.tar.gz` archives to RAM on every boot, this plugin simply **mounts** the existing block device. 
- **Instant Readiness**: Your AI agents are ready to execute the millisecond the mount command completes, with zero CPU overhead for extraction.
- **ZSTD Compression**: All agent binaries are stored using transparent ZSTD:1 compression, saving up to 40% of space on your Flash drive without sacrificing performance.

## 2. Decoupled Sync Scheduling

The synchronization of your data is governed by a background daemon that is completely decoupled from your interactive terminal sessions.

### How it Works:
- **Standalone Daemon**: A background subshell (`sync-daemon-<user>.sh`) runs independently of the Unraid WebGUI. It executes on a global heartbeat (default 10 minutes).
- **Session Independence**: Closing your browser tab or disconnecting from the terminal does **not** stop the sync cycle. Your work continues to be protected as long as the Unraid server is powered on.
- **Log Monitoring**: You will see pulses in the `debug.log` labeled `Global periodic sync heartbeat triggered`. This indicates the daemon is successfully mirroring your RAM data to the Flash drive.

## 3. User Management & Data Migration

Each Unraid user selected in the **Session Profile** has their own isolated home directory. 

### The "Legacy Cleanup" Sequence:
When you switch the **Terminal User** (e.g., from `root` to `aicliagent`), the plugin executes a professional **Legacy Cleanup** safety sequence:

1. **Final Flush**: The current user's RAM data is immediately synced to their Flash persistence folder.
2. **Persistence Migration**: The data on the Flash drive is moved from the old user's folder to the new user's folder.
3. **RAM Transition**: The active working directory in RAM is moved to ownership under the new user.
4. **Permission Update**: All files are recursively `chown`'d to ensure the new user has full read/write access to the migrated workspace.

> [!IMPORTANT]
> This sequence ensures that any unsaved work in RAM from your previous session is captured and carried over to the new user identity.

## 4. Troubleshooting & Maintenance

### Common Log Entries:
- **`Global periodic sync heartbeat triggered`**: Normal background operation.
- **`BLOCKING sync for <user>: Not the active user`**: A security guard preventing background processes from old sessions from overwriting the current active user's data.
- **`LEGACY CLEANUP: Cleaning up...`**: Occurs after an upgrade or system restart to ensure no "ghost" processes from old versions are interfering with the new architecture.

### Manual Synchronization:
You can force an immediate sync at any time by clicking the **Sync Now** button in the **Session Profile** settings. This is recommended before performing a manual reboot of the Unraid server.

### Advanced Recovery & Cleanup:
If the plugin is hanging during an upgrade or behaving inconsistently, you can use the built-in repair tool. This command surgically removes the plugin and its active processes while **guaranteeing the safety of your chat history and workspace files**.

Run this command from any Unraid terminal (SSH or Console):
```bash
aicli-repair
```

**What it does:**
1. **Force-Kills** all AI agent binaries, tmux sessions, and background sync daemons.
2. **Removes** the WebUI and plugin installation records from the Unraid system.
3. **Purges** all logs and temporary caches (NPM/Bun) from both RAM and USB.
4. **Preserves** your persistent chat history and workspace files in `/boot/config/plugins/unraid-aicliagents/persistence/`.

After running `aicli-repair`, you can simply reinstall the plugin from the Unraid "Plugins" tab, and your data will be automatically restored.

### Custom Storage Location:
By default, your AI history is saved to your Unraid Flash drive (`/boot/config/plugins/unraid-aicliagents/persistence`). 

To improve drive longevity or if you have massive amounts of data, you can move this to your main Array or a Cache pool:
1. Go to the **Session Profile** card in settings.
2. Click **Change** next to the **Storage Backend** field.
3. Select your new path (e.g., `/mnt/user/appdata/aicli-persistence`) and click **Migrate Data Now**.

**The "Flash vs. Array" Tradeoff:**
- **Flash (Default)**: Slower, but your AI agents are available even if the array is stopped.
- **Array/Pool**: Faster and safer for SSDs, but your AI agents will start with a **blank history** if the array is stopped.

**Fallback Volatile Mode:**
If you select an Array path and launch a terminal while the array is down, the plugin will automatically detect this and start in **Volatile Mode**. 
- You can still use the AI agents normally.
- Your session data will be saved to RAM only.
- **IMPORTANT**: To protect your permanent data, the volatile session will **NOT** be synced to your array. It will be lost when you restart the terminal or reboot the server.

## 5. Direct SSH & Remote Terminal Access

If you need to drop into a standard Unraid shell (via SSH or the web console) and run the agents directly, follow these steps to ensure your environment is consistent with the plugin's persistence system.

### One-Liner Environment Setup
Run this in your shell to correctly set the Node path and persistence redirect:
```bash
export PATH="/usr/local/emhttp/plugins/unraid-aicliagents/bin:$PATH"
export HOME="/tmp/unraid-aicliagents/work/$(whoami)/home"
```

### Agent-Specific Execution
Once the environment is set, you can execute agents directly using their absolute paths:

- **Claude**: `/usr/local/emhttp/plugins/unraid-aicliagents/agents/claude-code/node_modules/.bin/claude`
- **OpenCode**: `/usr/local/emhttp/plugins/unraid-aicliagents/agents/opencode/node_modules/.bin/opencode`
- **Gemini CLI**: `/usr/local/emhttp/plugins/unraid-aicliagents/agents/gemini-cli/node_modules/.bin/gemini`
- **NanoCoder**: `/usr/local/emhttp/plugins/unraid-aicliagents/agents/nanocoder/node_modules/.bin/nanocoder`

> [!TIP]
> **Persistence Warning**: If you run agents without setting the `HOME` variable as shown above, your chat history and configurations will NOT be synced to the Flash drive and will be lost on the next server reboot or plugin update.

## 6. Self-Healing SQLite Architecture

To ensure your agents (like OpenCode and Claude Code) always start reliably, the plugin performs a **Pre-Flight Integrity Check** on their underlying SQLite databases:

- **Automatic Repair**: If a database becomes malformed due to a sudden power loss or process crash, the plugin detects it before the agent launches.
- **Data Protection**: Corrupt databases are automatically quarantined (renamed to `.corrupt.<timestamp>`) and the agent is allowed to start with a fresh, healthy database. 
- **WAL Checkpointing**: During system shutdowns and plugin updates, the plugin performs a clean "Checkpoint" to merge temporary write logs into the main database file, ensuring maximum portability and reliability.

---
*Version: 2026.04.01.08*
*Architecture: Btrfs Loopback + Delta Sync + Zero-Archive Deployment*


