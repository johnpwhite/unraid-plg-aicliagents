#!/bin/bash
_INSTALLER_RESOLVER="$(dirname "${BASH_SOURCE[0]}")/../storage/resolve_paths.sh"
[ -f "$_INSTALLER_RESOLVER" ] || _INSTALLER_RESOLVER="${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/scripts/storage/resolve_paths.sh"
source "$_INSTALLER_RESOLVER" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# AICliAgents Installer: Storage Initialization (SquashFS + ZRAM Edition)

AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
CONFIG_FILE="${PLUGIN_BASE}/unraid-aicliagents.cfg"

# --- 1. Directory Scaffolding ---
log_step "Preparing SquashFS storage layer..."
mkdir -p "$AGENT_BASE"
mkdir -p "${PLUGIN_BASE}/persistence"
mkdir -p "${MOUNT_ROOT}/mnt"
mkdir -p "${MOUNT_ROOT}/work"

# --- 2. ZRAM Preparation ---
# We don't mount everything here, just ensure the module can be loaded.
if ! lsmod | grep -q zram; then
    log_status "    > Loading ZRAM module..."
    modprobe zram num_devices=1 >/dev/null 2>&1 || log_status "    [!] ZRAM module load failed (might be built-in)"
fi

log_ok "Storage scaffolding ready. Migration will proceed in the background."
