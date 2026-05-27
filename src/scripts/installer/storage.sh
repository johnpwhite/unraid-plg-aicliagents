#!/bin/bash
# AICliAgents Installer: Storage Initialization (SquashFS + ZRAM Edition)

AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
CONFIG_FILE="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"

# --- 1. Directory Scaffolding ---
log_step "Preparing SquashFS storage layer..."
mkdir -p "$AGENT_BASE"
mkdir -p "/boot/config/plugins/unraid-aicliagents/persistence"
mkdir -p "/tmp/unraid-aicliagents/mnt"
mkdir -p "/tmp/unraid-aicliagents/work"

# --- 2. ZRAM Preparation ---
# We don't mount everything here, just ensure the module can be loaded.
if ! lsmod | grep -q zram; then
    log_status "    > Loading ZRAM module..."
    modprobe zram num_devices=1 >/dev/null 2>&1 || log_status "    [!] ZRAM module load failed (might be built-in)"
fi

log_ok "Storage scaffolding ready. Migration will proceed in the background."
