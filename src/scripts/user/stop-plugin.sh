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
# R2 (CAPTURE_RESUME_ALL_CLOSE_PATHS): the shutdown-capture bridge.
source "$EMHTTP_DEST/src/scripts/storage/capture_resume.sh" 2>/dev/null || true
source "$EMHTTP_DEST/src/scripts/user/stop-session-agents.sh" 2>/dev/null || true

# Budget (secs) for the pre-kill resume capture when this script is invoked
# DIRECTLY (not via event/stopping). When event/stopping already captured it
# drops a guard marker so we skip a second, budget-stacking capture here.
CAPTURE_BUDGET=8
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

# ── Step 0.5: Capture resume ids BEFORE the kill sweep (R2) ──
# Disk fallback for every live session + best-effort full clean close within
# the budget, so chat continuity survives the shutdown. The kill sweep below is
# UNCHANGED (the hard guarantee). If event/stopping already captured (guard
# marker present), skip — don't stack budgets serially in the same shutdown.
if [ -f /tmp/unraid-aicliagents/.resume_captured ]; then
    status "Resume ids already captured by event/stopping — skipping (guard marker)."
    rm -f /tmp/unraid-aicliagents/.resume_captured 2>/dev/null || true
elif declare -f _capture_resume_before_stop >/dev/null 2>&1; then
    status "Capturing resume ids before kill sweep (budget ${CAPTURE_BUDGET}s)..."
    _capture_resume_before_stop "$CAPTURE_BUDGET"
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
    for pid in $(pgrep -f "$pattern" 2>/dev/null | grep -vx "$$" || true); do
        _safe_excluded "$pid" && continue
        kill -9 "$pid" >/dev/null 2>&1 || true
    done
}
# Graceful: SIGTERM the pattern, then poll up to <wait>s for a clean exit.
safe_pterm() {
    local pattern="$1" wait="${2:-5}" pid waited=0
    for pid in $(pgrep -f "$pattern" 2>/dev/null | grep -vx "$$" || true); do
        _safe_excluded "$pid" && continue
        kill -TERM "$pid" >/dev/null 2>&1 || true
    done
    while [ "$waited" -lt "$wait" ]; do
        pgrep -f "$pattern" >/dev/null 2>&1 || break
        sleep 1
        waited=$((waited + 1))
    done
}
stop_plugin_agents() {
    # Hard-kill retry wrappers first so they cannot respawn an agent.
    status "Stopping agent retry loops..."
    safe_pkill 'aicli-run-'
    safe_pkill 'aicli-shell'

    # Match the session environment, not a runtime name. This gives Node and
    # native agents the same flush window before the home bake.
    status "Gracefully stopping agents (SIGTERM, then bake-safe wait)..."
    stop_session_agent_processes 5

    # Catch a Node process that did not inherit the workspace session id, then
    # stop ttyd terminals after their agents have had the flush window.
    safe_pkill 'node .*(unraid-aicliagents|/\.aicli/)'
    safe_pkill 'ttyd.*(aicliterm|temp-terminal)-'
}
stop_plugin_agents
# 1d. Stop the per-user Secret Service daemons + their private session buses
#     (Bug #1042) so the keyring file is quiescent before the home bake, and
#     so a plugin upgrade picks up a new daemon binary on the next launch.
safe_pkill 'secret-service-daemon'
safe_pkill 'dbus-daemon .*unraid-aicliagents/secret-service'
# Now kill tmux sessions (the children are already dead)
if command -v tmux >/dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    # Bug #141: per-session servers (s-<sid>/tmux-<uid>) plus any pre-#141
    # session still live on the legacy shared socket.
    for _sock in /tmp/unraid-aicliagents/tmux/s-*/tmux-*/default \
                 /tmp/unraid-aicliagents/tmux/tmux-*/default; do
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
    # #372 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins"): bake
    # the upper each home's mount REALLY uses (zram or disk), not only the zram
    # folders — a home mounted on a disk upper was skipped here. RAM uppers go
    # first (home_uppers_shutdown_order). Fallback without resolve_paths.sh: the
    # zram folders, as before.
    _stop_home_uppers() {
        if declare -f home_uppers_shutdown_order >/dev/null 2>&1; then
            home_uppers_shutdown_order
            return 0
        fi
        local _d _u
        for _d in "$ZRAM_UPPER/homes"/*/upper; do
            [ -d "$_d" ] || continue
            _u="${_d%/upper}"; printf '%s\t%s\n' "${_u##*/}" "$_d"
        done
    }
    while IFS=$'\t' read -r user upper_dir; do
        [ -n "$user" ] && [ -d "$upper_dir" ] || continue
        has_real_files "$upper_dir" || continue
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
    done < <(_stop_home_uppers)
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

# #512: a REAL umount first, tried 3 times. Before #512 this step was `umount -l`
# only: a lazy unmount returns success while the kernel still holds the mount,
# so its loop keeps the layer file open and a deleted layer keeps its space on
# the flash. The plugin stops at array stop, shutdown and uninstall, and there
# Unraid must still be able to unmount the pools, so a mount that stays busy
# after the real tries is lazy-detached as a LOGGED last resort (it frees itself
# once the last holder exits). Paths that run while the server stays up never
# lazy-unmount (common.sh umount_or_keep).
_stop_umount() {
    local mnt="$1" i
    mountpoint -q "$mnt" 2>/dev/null || return 0
    for i in 1 2 3; do
        umount "$mnt" 2>/dev/null && return 0
        sleep "${AICLI_UMOUNT_RETRY_S:-1}"
    done
    mountpoint -q "$mnt" 2>/dev/null || return 0
    status "WARNING: $mnt still busy after 3 real unmounts; lazy-detaching so Unraid can stop (#512)"
    umount -l "$mnt" 2>/dev/null
    return 1
}

# 8a. Unmount home overlays
WORK_BASE="/tmp/unraid-aicliagents/work"
if [ -d "$WORK_BASE" ]; then
    # BOOT_DISK_SPACE.md R5: the cache binds of a plain home sit inside the home
    # mount and may live on a pool. Remove them first (lazily when busy: this
    # runs at array stop and shutdown), then the home, then the RAM cache.
    source "$EMHTTP_DEST/src/scripts/storage/common.sh" 2>/dev/null || true
    for mnt in "$WORK_BASE"/*/home; do
        _hc_id=""
        if declare -f home_cache_id_of_mount >/dev/null 2>&1; then
            _hc_id="$(home_cache_id_of_mount "$mnt" 2>/dev/null || true)"
        fi
        [ -z "$_hc_id" ] || home_cache_release_binds "$_hc_id" "$mnt" 1 2>/dev/null || true
        _stop_umount "$mnt"
        [ -z "$_hc_id" ] || home_cache_release_ram "$_hc_id" 1 2>/dev/null || true
    done
fi

# 8b. Unmount agent overlays
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): resolve_paths.sh is
# already sourced above — route through its agent_base(), falling back to the
# literal only if sourcing ever failed.
if declare -f agent_base >/dev/null 2>&1; then
    AGENT_BASE="$(agent_base)"
else
    AGENT_BASE="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
fi
if [ -d "$AGENT_BASE" ]; then
    for mnt in "$AGENT_BASE"/*; do
        [ -d "$mnt" ] && _stop_umount "$mnt"
    done
    # Issue #10: generations are mounted under $AGENT_BASE/.versions/<agent>/,
    # which the glob above skips, so it only reached the generation behind each
    # agent's symlink. A superseded generation kept for a running session stayed
    # mounted, its lower stayed busy in 8c, and its layer file held the pool.
    # Unmount every mount left below $AGENT_BASE, deepest first.
    while read -r mnt; do
        _stop_umount "$mnt"
    done < <(awk -v base="$AGENT_BASE/" 'index($2, base) == 1 {print $2}' /proc/mounts | sort -r)
fi

# 8c. Unmount individual SquashFS loop mounts (these hold /mnt/user open when home is on array)
if [ -d "/tmp/unraid-aicliagents/mnt" ]; then
    for mnt in /tmp/unraid-aicliagents/mnt/*; do
        _stop_umount "$mnt"
    done
fi

# 8d. Detach orphaned loop devices from our sqsh files
for loop in $(losetup -a 2>/dev/null | grep 'unraid-aicliagents' | cut -d: -f1); do
    losetup -d "$loop" 2>/dev/null
done

# 8e. Unmount ZRAM
_stop_umount "$ZRAM_UPPER"

# Clean runtime files
rm -f /tmp/unraid-aicliagents/.init_done
rm -f /var/run/aicliterm-*.sock
rm -f /var/run/unraid-aicliagents-*.pid

status "AICliAgents successfully stopped."
exit 0
