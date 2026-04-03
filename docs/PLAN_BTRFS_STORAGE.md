# Implementation Plan: Btrfs Sparse-Image Agent Storage

## 1. Objective
Refactor the AI Agent binary storage system to eliminate permanent RAM usage and maximize USB Flash drive longevity. We will replace the current `.tar.gz` extraction-to-RAM model with a persistent, compressed Btrfs loopback image stored on the Flash drive.

## 2. Architectural Specification

### 2.1 The Storage Container (`aicli-agents.img`)
- **Format**: Btrfs (Advanced Linux Filesystem).
- **Type**: Sparse File (Logical capacity is high, but physical space on USB is only consumed as needed).
- **Path**: `/boot/config/plugins/unraid-aicliagents/aicli-agents.img`
- **Mount Point**: `/usr/local/emhttp/plugins/unraid-aicliagents/agents` (RAM-based mount point for the loopback device).

### 2.2 Filesystem Optimizations (Flash Longevity & Performance)
To minimize physical writes to the USB 2.0 drive and maximize speed, the image will be mounted with the following flags:
- `compress=zstd:1`: Transparent background compression. Expecting 50-70% reduction in physical data written for Javascript/Node source files.
- `noatime,nodiratime`: Disables "last access" timestamp updates. This ensures that starting an agent (reading thousands of files) results in **zero** physical writes to the USB.
- `autodefrag`: Ensures the small-file write patterns of `npm` do not lead to image fragmentation.
- `loop`: Standard loopback device mounting.

## 3. Core Logic Components

### 3.1 Initialization & Mounting (`storage.sh`)
1. **Check for Image**: If `aicli-agents.img` does not exist:
   - Calculate free space on Flash.
   - Create a 2GB sparse file: `truncate -s 2G <path>`.
   - Format: `mkfs.btrfs -m single <path>`.
2. **Mount Logic**: 
   - Ensure the mount point exists in `/usr/local`.
   - Execute the optimized `mount` command.
   - Handle "Already Mounted" states to prevent errors during plugin upgrades.

### 3.2 Automated Migration (One-Time)
On the first run of the new version:
1. Detect if the legacy `pkg-cache/` folder exists on Flash.
2. If the new Btrfs image is mounted and empty:
   - Unpack existing `.tar.gz` caches directly into the mounted Btrfs image.
   - Once verified, rename `pkg-cache` to `pkg-cache.legacy` to prevent re-migration.

### 3.3 Dynamic Expansion & Safety
- **Guardrail**: Implement `aicli_get_flash_headroom()` in PHP. Block any expansion if the physical USB has less than 100MB of free space.
- **Expansion Logic**:
  - `truncate -s +500M <path>` (Increase sparse file size).
  - `btrfs filesystem resize max <mount_point>` (Expand the live filesystem instantly).

### 3.4 Error Handling (`installAgent`)
- **Detection**: Monitor `npm install` output for `ENOSPC`.
- **Graceful Recovery**: 
  - If the image fills up, `rm -rf` the failed staging directory.
  - Return a structured error to the UI: `status: 'error', reason: 'disk_full'`.
  - UI will provide a direct link/button to the Settings page to trigger an expansion.

## 4. UI/UX Enhancements (`AICliAgentsSettings.page`)

### 4.1 Storage Monitor
Add a dashboard-style entry in the "System Info" or "Session Profile" card:
- **Label**: Agent Binary Storage
- **Value**: `[Used Space] / [Total Capacity]` (e.g., `140MB / 2048MB`).
- **Optimization Status**: Display "Compressed (ZSTD)" and "No-Atime Active".

### 4.2 Expand Button
A dedicated button to increase the image size by 500MB increments, protected by the Flash headroom check.

## 5. Decommissioning Legacy Code
- Remove the `pkg-cache` packing logic from `installAgent`.
- Remove the `.tar.gz` unpacking logic from `storage.sh`.
- Remove the `AICLI_LOG_DEBUG` pre-copy discovery logic once the direct-to-image install is verified.

---
*Status: Draft Specification*
*Author: Gemini CLI*
*Date: 2026.03.31*
