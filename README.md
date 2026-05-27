# Unraid AI CLI Agents

Run modern AI coding agents — Claude Code, Gemini CLI, GitHub Copilot, OpenCode, Goose, and more — directly inside the Unraid WebUI, with persistent workspaces and tmux session reattach.

## Features

- **11 agents out of the box** — Claude Code, Gemini CLI, GitHub Copilot, OpenCode, Kilo Code, Codex CLI, Goose, Qwen Code, Pi Coder, Factory (Droid) CLI, NanoCoder. Install, upgrade, and switch versions from a single AI Agent Marketplace page.
- **Web terminal in the Unraid GUI** — every workspace opens an embedded xterm session backed by tmux on the server. Close the browser tab, come back tomorrow, your agent's still running.
- **Reattach from your local terminal** — register an SSH public key in Settings, click the key icon on a workspace, paste the copied command into Windows Terminal / iTerm / your shell. No new client, no extra ports, no protocol handlers.
- **Per-workspace environment & secrets** — API keys, env vars, and CLI args can be set per-agent and overridden per-workspace, with hot-apply so changes take effect on the next session without rebuild.
- **Persistent storage with low USB wear** — agent binaries and home directories live on compressed read-only SquashFS layers with a ZRAM write buffer. Persists across reboots without thrashing your flash drive. Configurable storage location (flash, array, cache pool, ZFS, unassigned).
- **Array-aware** — when the array is stopped or storage is unavailable, the plugin surfaces a clear overlay and offers an Emergency Mode (install an agent into RAM, work until the array's back). Sessions on flash continue running uninterrupted when the array stops.
- **Version management** — per-agent version picker, channel selection (latest/beta/next), upgrade notifications via Unraid's built-in notification system.
- **Graceful upgrades** — installing or upgrading an agent enumerates active sessions, captures each agent's resume id, closes them cleanly, swaps the binary, and offers a "Resume" button when you return.

## Requirements

- Unraid 7.2 or later
- Each agent has its own provider-side requirements (API key, login, etc.) — set them on the agent's Settings card

## Installation

Available via Community Applications. Search for "AI CLI Agents".

## Support

[Forum thread](https://forums.unraid.net/topic/197460-plugin-support-unraid-tab-for-ai-cli-coding-agents-gemini-cli-claude-code-opencode-kilo-code-pi-coder-codex-cli-factory-droid-cli-copilot-nano-coder/)
