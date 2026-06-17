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

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

# Ensure plugin binaries (mksquashfs, node, etc.) are in PATH
export PATH="$EMHTTP_DEST/bin:$EMHTTP_DEST/src/scripts/storage:$PATH"

# Source canonical path resolver (Phase 1 — Storage Durability Supervisor)
source "$EMHTTP_DEST/src/scripts/storage/resolve_paths.sh" 2>/dev/null || true
source "$EMHTTP_DEST/src/scripts/storage/atomic_write_layer.sh" 2>/dev/null || true
# F6 (WP#1331): the SINGLE manifest writer (shutdown delta bake's manifest record).
source "$EMHTTP_DEST/src/scripts/storage/manifest_write.sh" 2>/dev/null || true

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

# ── Step 1: Graceful agent shutdown, then SIGKILL ──
# Retry-loop wrappers are hard-killed first (so they cannot respawn an agent),
# then the agents get a SIGTERM window to flush their state into the home
# overlay, then any straggler is SIGKILLed. Both kill helpers carry an
# exe-path exclusion for hypervisor/init processes — belt-and-braces; the
# patterns never match qemu/libvirt/init anyway.
_safe_excluded() {
    local exe
    exe=$(readlink "/proc/$1/exe" 2>/dev/null)
    case "$exe" in
        */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) return 0 ;;
    esac
    return 1
}
safe_pkill() {
    local pattern="$1" pid
    for pid in $(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true); do
        _safe_excluded "$pid" && continue
        kill -9 "$pid" >/dev/null 2>&1 || true
    done
}
# Graceful: SIGTERM the pattern, then poll up to <wait>s for a clean exit.
safe_pterm() {
    local pattern="$1" wait="${2:-5}" pid waited=0
    for pid in $(pgrep -f "$pattern" 2>/dev/null | grep -v "$$" || true); do
        _safe_excluded "$pid" && continue
        kill -TERM "$pid" >/dev/null 2>&1 || true
    done
    while [ "$waited" -lt "$wait" ]; do
        pgrep -f "$pattern" >/dev/null 2>&1 || break
        sleep 1
        waited=$((waited + 1))
    done
}
# 1a. Hard-kill the retry-loop wrappers FIRST so nothing respawns an agent
#     while we are gracefully stopping it (they carry no data to flush).
status "Stopping agent retry loops..."
safe_pkill 'aicli-run-'                                    # Retry loop scripts
safe_pkill 'aicli-shell'                                   # Shell wrappers
# 1b. Graceful SIGTERM to the agents — they write SQLite DBs + chat history
#     into the HOME overlay, so give them a window to flush cleanly before the
#     home bake, otherwise the shutdown delta captures a torn mid-write state.
#     Path-anchored node pattern so we never match an unrelated host service.
status "Gracefully stopping agents (SIGTERM, then bake-safe wait)..."
safe_pterm 'node .*(unraid-aicliagents|/\.aicli/)' 5
# 1c. SIGKILL any straggler that ignored SIGTERM, plus the ttyd terminals.
safe_pkill 'node .*(unraid-aicliagents|/\.aicli/)'
safe_pkill 'ttyd.*(aicliterm|temp-terminal)-'
# 1d. Stop the per-user Secret Service daemons + their private session buses
#     (Bug #1042) so the keyring file is quiescent before the home bake, and
#     so a plugin upgrade picks up a new daemon binary on the next launch.
safe_pkill 'secret-service-daemon'
safe_pkill 'dbus-daemon .*unraid-aicliagents/secret-service'
# Now kill tmux sessions (the children are already dead)
if command -v tmux >/dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    for _sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | while read -r sess; do
            tmux -S "$_sock" kill-session -t "$sess" >/dev/null 2>&1
        done
    done
fi

# ── Step 2: Wait for process table cleanup ──
sleep 2

# ── Step 3: Final sweep (catch anything that slipped through) ──
safe_pkill 'aicli-run-'
safe_pkill 'node .*(unraid-aicliagents|/\.aicli/)'

# ── Step 6: Bake dirty ZRAM HOME data to persistence ──
# HOME ONLY — and delta only, never a consolidation. Home holds the user's
# irreplaceable data (chat history, workspaces, secrets); agent overlays are
# just installed software, re-installable from the registry, so they are NOT
# baked at shutdown — that keeps the whole (time-limited) shutdown budget for
# the home bake, the only thing that matters. atomic_write_layer is
# append-only: it writes a new delta and never touches an existing layer.
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

    # Bake dirty home layers (home only — see header).
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
                # Follow-on 3: track the shutdown delta in the manifest IMMEDIATELY
                # (mirror op_bake Step 7a) instead of leaving it untracked-until-
                # reconcile. LEAN — bake + manifest record only, no refresh/reclaim
                # (wrong under the shutdown budget). addLayer is idempotent + upserts.
                # F6 (WP#1331): the SINGLE manifest writer.
                manifest_record_layer "home" "$user" "$home_path" "$DELTA_NAME" \
                    || warn "  Manifest record failed for $DELTA_NAME (reconcile will recover)."
            else
                warn "Home delta bake failed for $user."
            fi
        done
    fi
    [ "$BAKED" -gt 0 ] && status "Persisted $BAKED home delta(s) to Flash." || status "No dirty home data to persist."
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
