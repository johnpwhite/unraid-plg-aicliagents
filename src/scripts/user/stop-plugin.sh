#!/bin/bash
# AICliAgents Graceful Stop Utility
# Called by event/stopping when the array stops or server shuts down.
#
# IMPORTANT: If storage is on Flash/USB (/boot/...), this script should only run
# during a full server shutdown — NOT when just stopping the array.
# The event/stopping handler checks this before calling us.

EMHTTP_DEST="/usr/local/emhttp/plugins/unraid-aicliagents"
LOG_FILE="/tmp/unraid-aicliagents/debug.log"
ZRAM_UPPER="/tmp/unraid-aicliagents/zram_upper"

# Ensure plugin binaries (mksquashfs, node, etc.) are in PATH
export PATH="$EMHTTP_DEST/bin:$EMHTTP_DEST/src/scripts/storage:$PATH"

# Source canonical path resolver (Phase 1 — Storage Durability Supervisor)
source "$EMHTTP_DEST/src/scripts/storage/resolve_paths.sh" 2>/dev/null || true
source "$EMHTTP_DEST/src/scripts/storage/atomic_write_layer.sh" 2>/dev/null || true

PERSIST_PATH=$(agent_persist_path 2>/dev/null)
[ -z "$PERSIST_PATH" ] && PERSIST_PATH="/boot/config/plugins/unraid-aicliagents"

status() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [INFO] [Stop] $1" >> "$LOG_FILE"
}
warn() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [WARN] [Stop] $1" >> "$LOG_FILE"
}

status "--- AICliAgents Stop Sequence Start ---"

# ── Step 0: Stop the Storage Durability Supervisor first ──
# Path-anchored: only targets the supervisor script PID -- never a broad pkill.
SUPERVISOR_PIDFILE="/var/run/aicli-supervisor.pid"
SUPERVISOR_SCRIPT="$EMHTTP_DEST/src/scripts/supervisor/aicli-supervisor.sh"
if [ -f "$SUPERVISOR_PIDFILE" ]; then
    SUPERVISOR_PID="$(cat "$SUPERVISOR_PIDFILE" 2>/dev/null || echo 0)"
    if [ "$SUPERVISOR_PID" -gt 0 ] 2>/dev/null; then
        SUPERVISOR_CMDLINE="$(tr '\0' ' ' < "/proc/$SUPERVISOR_PID/cmdline" 2>/dev/null || echo '')"
        if echo "$SUPERVISOR_CMDLINE" | grep -qF "$SUPERVISOR_SCRIPT"; then
            status "Sending TERM to supervisor (pid $SUPERVISOR_PID)..."
            kill -TERM "$SUPERVISOR_PID" 2>/dev/null || true
            # Give it up to 5s to exit cleanly before we proceed
            waited=0
            while [ "$waited" -lt 5 ]; do
                kill -0 "$SUPERVISOR_PID" 2>/dev/null || break
                sleep 1
                waited=$((waited + 1))
            done
            if kill -0 "$SUPERVISOR_PID" 2>/dev/null; then
                status "Supervisor did not exit in 5s -- sending KILL."
                kill -KILL "$SUPERVISOR_PID" 2>/dev/null || true
            fi
        else
            status "Supervisor pidfile present but PID $SUPERVISOR_PID does not own the script (stale). Removing."
        fi
        rm -f "$SUPERVISOR_PIDFILE" 2>/dev/null || true
    fi
fi

# ── Step 1: Kill ALL our processes at once with SIGKILL ──
# Previous approach (kill tmux first, then node) didn't work because:
# - tmux kill-session sends SIGHUP which bash scripts can survive
# - aicli-run-*.sh retry loop respawns the agent before our next pkill fires
# Solution: SIGKILL everything simultaneously so nothing can respawn.
# Safe kill helper: pkill but with an explicit exe-path exclusion for
# hypervisor/init processes. Belt-and-braces — these patterns should never
# match qemu/libvirt/init anyway, but the filter makes it guaranteed.
safe_pkill() {
    local pattern="$1"
    local pids
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true)
    for pid in $pids; do
        local exe
        exe=$(readlink "/proc/$pid/exe" 2>/dev/null)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac
        kill -9 "$pid" >/dev/null 2>&1 || true
    done
}
status "Killing all AICliAgents processes..."
safe_pkill 'aicli-run-'                                    # Retry loop scripts
safe_pkill 'aicli-shell'                                   # Shell wrappers
# Path-anchored node pattern — requires the cmdline to carry a plugin-owned
# path so we cannot accidentally match unrelated Node services running on the
# host. The old agent-name regex would match e.g. `node /opt/factory-bot/…`.
safe_pkill 'node .*(unraid-aicliagents|/\.aicli/)'
safe_pkill 'ttyd.*(aicliterm|temp-terminal)-'
# Now kill tmux sessions (the children are already dead)
if command -v tmux >/dev/null 2>&1; then
    tmux ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | while read -r sess; do
        tmux kill-session -t "$sess" >/dev/null 2>&1
    done
fi

# ── Step 2: Wait for process table cleanup ──
sleep 2

# ── Step 3: Final sweep (catch anything that slipped through) ──
safe_pkill 'aicli-run-'
safe_pkill 'node .*(unraid-aicliagents|/\.aicli/)'

# ── Step 6: Bake dirty ZRAM data to persistence (delta only, NO consolidation) ──
if [ -d "$PERSIST_PATH" ] && [ -r "$PERSIST_PATH" ]; then
    BAKED=0
    # Helper: check if an upper dir has real files (not just overlayfs whiteouts/opaque markers)
    has_real_files() {
        local dir="$1"
        # Count regular files (excludes char devices which are overlayfs whiteouts)
        local count
        count=$(find "$dir" -type f 2>/dev/null | head -1 | wc -l)
        [ "$count" -gt 0 ]
    }

    # Bake dirty home layers
    if [ -d "$ZRAM_UPPER/homes" ]; then
        for upper_dir in "$ZRAM_UPPER/homes"/*/upper; do
            [ -d "$upper_dir" ] || continue
            has_real_files "$upper_dir" || continue
            user=$(basename "$(dirname "$upper_dir")")
            home_path=$(home_persist_path "$user" 2>/dev/null || echo "$PERSIST_PATH")
            status "Baking home delta for $user..."
            DELTA_NAME=$(atomic_write_layer "home" "$user" "$home_path" "$upper_dir" "delta" 2>>"$LOG_FILE")
            if [ -n "$DELTA_NAME" ]; then
                BAKED=$((BAKED + 1))
                status "  Saved $DELTA_NAME for $user."
            else
                warn "Home delta bake failed for $user."
            fi
        done
    fi
    # Bake dirty agent layers
    if [ -d "$ZRAM_UPPER/agents" ]; then
        for upper_dir in "$ZRAM_UPPER/agents"/*/upper; do
            [ -d "$upper_dir" ] || continue
            has_real_files "$upper_dir" || continue
            agent=$(basename "$(dirname "$upper_dir")")
            agent_path=$(agent_persist_path 2>/dev/null || echo "$PERSIST_PATH")
            status "Baking agent delta for $agent..."
            DELTA_NAME=$(atomic_write_layer "agent" "$agent" "$agent_path" "$upper_dir" "delta" 2>>"$LOG_FILE")
            if [ -n "$DELTA_NAME" ]; then
                BAKED=$((BAKED + 1))
                status "  Saved $DELTA_NAME for $agent."
            else
                warn "Agent delta bake failed for $agent."
            fi
        done
    fi
    [ "$BAKED" -gt 0 ] && status "Persisted $BAKED delta(s) to Flash." || status "No dirty data to persist."
else
    warn " Storage path $PERSIST_PATH not accessible. Skipping final sync."
fi

# ── Step 7: Clean up emergency mode if active ──
if [ -f /tmp/unraid-aicliagents/.emergency_mode ]; then
    status "Cleaning up emergency mode state..."
    rm -rf /tmp/unraid-aicliagents/emergency_home
    rm -f /tmp/unraid-aicliagents/.emergency_mode
fi

# ── Step 8: Unmount all storage (top-down: overlays first, then loop mounts) ──
status "Unmounting storage..."

# 8a. Unmount home overlays
WORK_BASE="/tmp/unraid-aicliagents/work"
if [ -d "$WORK_BASE" ]; then
    for mnt in "$WORK_BASE"/*/home; do
        mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8b. Unmount agent overlays
AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
if [ -d "$AGENT_BASE" ]; then
    for mnt in "$AGENT_BASE"/*; do
        [ -d "$mnt" ] && mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8c. Unmount individual SquashFS loop mounts (these hold /mnt/user open when home is on array)
if [ -d "/tmp/unraid-aicliagents/mnt" ]; then
    for mnt in /tmp/unraid-aicliagents/mnt/*; do
        mountpoint -q "$mnt" 2>/dev/null && umount -l "$mnt" 2>/dev/null
    done
fi

# 8d. Detach orphaned loop devices from our sqsh files
for loop in $(losetup -a 2>/dev/null | grep 'unraid-aicliagents' | cut -d: -f1); do
    losetup -d "$loop" 2>/dev/null
done

# 8e. Unmount ZRAM
mountpoint -q "$ZRAM_UPPER" 2>/dev/null && umount -l "$ZRAM_UPPER" 2>/dev/null

# Clean runtime files
rm -f /tmp/unraid-aicliagents/.init_done
rm -f /var/run/aicliterm-*.sock
rm -f /var/run/unraid-aicliagents-*.pid

status "AICliAgents successfully stopped."
exit 0
