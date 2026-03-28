# Specification: AI Agent Lifecycle & Standardized Caching

**Version:** 1.0.0  
**Status:** Approved  
**Target:** Unraid 7.2+

## 1. Overview
To ensure maximum performance, minimal flash wear, and architectural symmetry, all AI agents in the `unraid-aicliagents` plugin follow a unified **"Binary-First"** lifecycle. This approach prioritizes standalone bundled assets over heavy NPM installations while standardizing how these assets are cached on the USB boot drive.

## 2. Core Principles
1.  **RAM-Only Execution**: All agents MUST execute from `/usr/local/emhttp/plugins/unraid-aicliagents/bin/` (RAM) to prevent constant USB flash wear.
2.  **Binary-First Source**: If a standalone bundle (JS/MJS) or a pre-compiled binary exists, it MUST be used instead of a full `npm install`.
3.  **Unified Cache Structure**: All persistent agent data survives reboots via a single, standardized folder: `/boot/config/plugins/unraid-aicliagents/pkg-cache/`.
4.  **Flexible Source, Identical Lifecycle**: The *download* method may vary per agent (GitHub API, NPM Registry, direct wget), but the *caching* and *restore* logic is identical.

## 3. The Lifecycle Flow

### Phase A: Installation (Download & Prep)
1.  **Source Detection**:
    - **Gemini CLI**: Download `gemini.js` bundle from GitHub Releases.
    - **Claude Code**: Download standalone binary from `claude.ai/install.sh`.
    - **OpenCode/Kilo/Pi/Codex**: Download standalone `.tar.gz` or `.zip` bundles from GitHub.
2.  **Staging**: Move the downloaded asset into the RAM `bin/` directory.
3.  **Permissions**: Ensure the file is executable (`chmod +x`).

### Phase B: Persist (Caching to USB)
1.  **Compression**: Create a compressed archive of the agent's files:
    - `tar -czf /boot/.../pkg-cache/<agent-id>.tar.gz -C /usr/.../bin/ <agent-files>`
2.  **Verification**: Confirm the tarball exists on the boot drive.

### Phase C: Restore (Boot-Time)
On system boot (via `.plg` or `rc` script):
1.  Iterate through all `.tar.gz` files in `/boot/.../pkg-cache/`.
2.  Extract each archive into the RAM `bin/` directory.
3.  This ensures agents are available immediately even without an internet connection (Offline Persistence).

## 4. Agent Mapping Table

| Agent ID | Executable Name | Source Method | Cache Filename |
| :--- | :--- | :--- | :--- |
| `gemini-cli` | `aicli` | GitHub Bundle (wget) | `gemini-cli.tar.gz` |
| `claude-code` | `claude` | Standalone Binary (curl) | `claude-code.tar.gz` |
| `opencode` | `opencode` | GitHub Release (wget) | `opencode.tar.gz` |
| `kilocode` | `kilo` | GitHub Release (wget) | `kilocode.tar.gz` |
| `pi-coder` | `pi` | GitHub Release (wget) | `pi-coder.tar.gz` |
| `codex-cli` | `codex` | GitHub Release (wget) | `codex-cli.tar.gz` |

## 5. Preview Toggle Logic
- **Enabled**: Fetch the latest "Pre-release" or "Beta" tag (GitHub) or the `@next` tag (NPM).
- **Disabled**: Fetch the "Latest" stable release.

## 6. Benefits
- **Speed**: Installing a 300KB-5MB binary is 10x faster than `npm install`.
- **Reliability**: No `node_modules` dependency hell or NPM registry outages once cached.
- **Longevity**: Reduces USB writes by ~95% compared to raw `node_modules` persistence.
