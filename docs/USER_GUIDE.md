# AI CLI Agents: User & Architecture Guide

This guide explains the internal architecture of the AI CLI Agents plugin, focusing on the hybrid persistence system, synchronized scheduling, and user management logic.

## 1. Hybrid Persistence Architecture

To balance performance with the reliability of the Unraid Flash drive (USB), AI CLI Agents uses a dual-layer storage system:

- **RAM Working Volume (`/tmp/unraid-aicliagents/work/<user>`)**: 
  - All active terminal operations, agent binaries, and temporary files run directly from RAM.
  - This prevents excessive wear on your USB Flash drive and ensures zero-latency file operations for AI agents.
- **Flash Persistence Store (`/boot/config/plugins/unraid-aicliagents/persistence/<user>/home`)**:
  - This is where your data lives permanently.
  - On system boot or plugin installation, data is restored from Flash to RAM.
  - Periodically, or upon manual trigger, the RAM volume is mirrored back to Flash using `rsync`.

## 2. Decoupled Sync Scheduling

The synchronization of your data is governed by a background daemon that is completely decoupled from your interactive terminal sessions.

### How it Works:
- **Standalone Daemon**: A background subshell (`sync-daemon-<user>.sh`) runs independently of the Unraid WebGUI. It executes on a global heartbeat (default 10 minutes).
- **Session Independence**: Closing your browser tab or disconnecting from the terminal does **not** stop the sync cycle. Your work continues to be protected as long as the Unraid server is powered on.
- **Log Monitoring**: You will see pulses in the `debug.log` labeled `Global periodic sync heartbeat triggered`. This indicates the daemon is successfully mirroring your RAM data to the Flash drive.

## 3. User Management & Data Migration

Each Unraid user selected in the **Session Profile** has their own isolated home directory. 

### The "Flush-then-Move" Sequence:
When you switch the **Terminal User** (e.g., from `root` to `aicliagent`), the plugin executes a "Scorched Earth" safety sequence to prevent data loss:

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
- **`SCORCHED EARTH: Cleaning up...`**: Occurs after an upgrade or system restart to ensure no "ghost" processes from old versions are interfering with the new architecture.

### Manual Synchronization:
You can force an immediate sync at any time by clicking the **Sync Now** button in the **Session Profile** settings. This is recommended before performing a manual reboot of the Unraid server.

---
*Version: 2026.03.17*
