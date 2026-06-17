#!/bin/bash
# AICliAgents Modular Uninstaller (v29)
# This script handles the heavy lifting of cleaning up active processes, 
# temp artifacts, and emhttp assets during plugin removal.

# --- Environment ---
NAME="unraid-aicliagents"
CONFIG_DIR="/boot/config/plugins/$NAME"
EMHTTP_DEST="/usr/local/emhttp/plugins/$NAME"
LOG_FILE="$CONFIG_DIR/uninstall.log"

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

# --- Log Status Helper ---
log_status() { echo "$1" >&3; echo "[$(date +%T)] $1" >> "$LOG_FILE" 2>/dev/null; }
export -f log_status

# Safe PID filter — drops any pid whose /proc/PID/exe resolves to a
# hypervisor/init binary. Belt-and-braces guard so a pattern cross-match
# (e.g. a VM whose cmdline happens to contain "gemini") can never take down
# user VMs during plugin uninstall.
_safe_filter_pids() {
    local out=""
    for pid in $1; do
        [ -z "$pid" ] && continue
        local exe
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac
        out+="$pid "
    done
    echo "$out"
}
# Graceful process termination: SIGTERM first, wait, then SIGKILL if needed
graceful_kill() {
    local pattern="$1"
    local pids
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
    pids=$(_safe_filter_pids "$pids")
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -15 > /dev/null 2>&1 || true
        sleep 2
        pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
        pids=$(_safe_filter_pids "$pids")
        if [ -n "$pids" ]; then
            echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
        fi
    fi
}

# 1. Terminate Active Processes & Sessions
# -----------------------------------------------------------------
log_status "  [1/4] Terminating AI Agent Sessions..."
graceful_kill "ttyd.*aicliterm-"
if command -v tmux >/dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    for _sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep -E "^aicli-agent-" | xargs -r -I {} tmux -S "$_sock" kill-session -t "{}" > /dev/null 2>&1 || true
    done
fi
# Path-anchored: requires a plugin-owned path in the cmdline so we cannot
# cross-match unrelated host node services or — critically — qemu VM cmdlines
# that share a substring with one of our agent names.
graceful_kill "node .*(unraid-aicliagents|/\\.aicli/)"

# 2. Cleanup Runtime Bloat
# -----------------------------------------------------------------
log_status "  [2/4] Purging RAM artifacts (/tmp)..."
rm -rf /tmp/unraid-aicliagents
rm -rf /var/run/aicli-sessions
rm -f /tmp/aicli-*.sh

# 3. Prune USB Artifacts (Optional/Requested)
# -----------------------------------------------------------------
if [ -d "$CONFIG_DIR" ]; then
    log_status "  [3/4] Pruning USB non-config artifacts..."
    rm -f "$CONFIG_DIR/aicli.mjs"
    rm -f "$CONFIG_DIR/debug.log"
    rm -f "$CONFIG_DIR/versions.json"
    rm -f "$CONFIG_DIR"/*.tar.gz
    rm -rf "$CONFIG_DIR/pkg-cache"
fi

# 4. Remove event hooks and plugin files
# -----------------------------------------------------------------
log_status "  [4/4] Removing plugin from WebGUI..."
rm -f /usr/local/emhttp/plugins/dynamix/events/stopping/aicli_sync
rm -f /usr/local/emhttp/plugins/dynamix/events/stopping_array/aicli_sync
rm -f /usr/local/emhttp/plugins/dynamix/events/disks_mounted/aicli_restore
rm -f /etc/cron.d/unraid-aicliagents.agent-check
rm -f /etc/cron.d/unraid-aicliagents.health-check
/usr/local/sbin/update_cron 2>/dev/null || true
if [ -d "$EMHTTP_DEST" ]; then
    rm -f /usr/local/bin/aicli-repair
    # If it's a symlink (Dev mode), just delete the link
    if [ -L "$EMHTTP_DEST" ]; then
        rm "$EMHTTP_DEST"
    else
        rm -rf "$EMHTTP_DEST"
    fi
fi
rm -f /var/log/plugins/${NAME}.plg

log_status " "
log_status "==========================================================="
log_status "                UNINSTALL COMPLETE                        "
log_status "==========================================================="
log_status "  [DONE] Binaries and UI files removed"
log_status "  [DONE] Runtime processes terminated"
log_status "  [DONE] Agent package caches pruned"
log_status " "
log_status "  [NOTE] YOUR SETTINGS HAVE BEEN PRESERVED:"
log_status "  > Config:     $CONFIG_DIR/*.cfg"
log_status "  > Persistence: $CONFIG_DIR/persistence/"
log_status " "
log_status "  [LOGS] Detailed uninstall trace at: $LOG_FILE"
log_status "==========================================================="

exit 0
