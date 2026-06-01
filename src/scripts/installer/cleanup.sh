#!/bin/bash
# AICliAgents Installer: Pre-Upgrade Process Eviction & Session Backup (v41)

# Source canonical path resolver (Phase 1 — Storage Durability Supervisor)
source "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/resolve_paths.sh" 2>/dev/null || true

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="/tmp/unraid-aicliagents/tmux"

# Graceful process termination: SIGTERM first, wait, then SIGKILL if needed.
# Hard guard against ever touching VM / hypervisor / init processes even if a
# future pattern over-matches. Builds a shrunk PID list that excludes any PID
# whose /proc/PID/exe resolves to qemu/libvirt/systemd/init/virsh/kvm binaries.
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

# --- 0a. STOP STORAGE DURABILITY SUPERVISOR ---
# The supervisor must be stopped before persistence + eviction so the installer
# does not race the new supervisor instance launched at the end of the PLG.
# Without this stop, the new supervisor invokes _pidfile_valid(), sees the old
# PID still alive, logs "Another supervisor instance is running" and bails —
# leaving the box without a supervisor until the InitService lazy backstop fires
# on the next page load. Bake/manifest writes during cleanup also race the old
# supervisor's reconcile tick if it stays alive.
SUPERVISOR_SH="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/aicli-supervisor.sh"
SUPERVISOR_PIDFILE="/var/run/aicli-supervisor.pid"
if [ -f "$SUPERVISOR_PIDFILE" ] && [ -f "$SUPERVISOR_SH" ]; then
    OLD_SUP_PID=$(cat "$SUPERVISOR_PIDFILE" 2>/dev/null || echo "")
    if [ -n "$OLD_SUP_PID" ] && kill -0 "$OLD_SUP_PID" 2>/dev/null; then
        log_status "    > Stopping old supervisor (pid $OLD_SUP_PID) before upgrade..."
        bash "$SUPERVISOR_SH" stop >/dev/null 2>&1 || true
        # Poll up to 15s for clean exit. The supervisor's own SIGTERM handler
        # writes a final work-state, releases its lock, and exits — usually < 2s.
        for _i in $(seq 1 15); do
            kill -0 "$OLD_SUP_PID" 2>/dev/null || break
            sleep 1
        done
        if kill -0 "$OLD_SUP_PID" 2>/dev/null; then
            log_status "    > Supervisor did not exit in 15s — sending SIGKILL"
            kill -9 "$OLD_SUP_PID" 2>/dev/null || true
            rm -f "$SUPERVISOR_PIDFILE" 2>/dev/null || true
        fi
    fi
fi

# Bug #513: orphan supervisor sweep. The pidfile-based stop above only catches
# the supervisor whose pid is in the pidfile. If a previous instance lost its
# pidfile (a sibling _do_stop removed it while the parent kept ticking), the
# orphan keeps running invisibly and races the new supervisor's queue.
# Path-anchored pgrep — must include the absolute script path so a user shell
# named "aicli-supervisor" or any unrelated process cannot match.
# Bug #578: trailing " start" restricts matches to the long-running daemon
# subcommand. Without it, pgrep also catches one-shot CLI invocations
# (cleanup-phantoms, status, stop) running concurrently — the reaper then
# burns 5+s waiting on a process that was already exiting.
SUPERVISOR_PATTERN="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/supervisor/aicli-supervisor.sh start"
ORPHAN_PIDS=$(pgrep -f "$SUPERVISOR_PATTERN" 2>/dev/null | grep -v "^$$\$" || true)
ORPHAN_PIDS=$(_safe_filter_pids "$ORPHAN_PIDS")
if [ -n "$ORPHAN_PIDS" ]; then
    log_status "    > Orphan supervisor process(es) detected: $ORPHAN_PIDS — terminating"
    echo "$ORPHAN_PIDS" | xargs -r kill -15 2>/dev/null || true
    sleep 2
    ORPHAN_PIDS=$(pgrep -f "$SUPERVISOR_PATTERN" 2>/dev/null | grep -v "^$$\$" || true)
    ORPHAN_PIDS=$(_safe_filter_pids "$ORPHAN_PIDS")
    if [ -n "$ORPHAN_PIDS" ]; then
        log_status "    > Orphan supervisor still alive — sending SIGKILL"
        echo "$ORPHAN_PIDS" | xargs -r kill -9 2>/dev/null || true
    fi
    rm -f "$SUPERVISOR_PIDFILE" 2>/dev/null || true
fi

# --- 0b. WP #941: MIGRATION_NEEDED gate (hot-swap by default) ---
# Routine upgrades only replace files in src/ (a sibling tree to the mounted
# agent + home overlays). Killing sessions, unmounting overlays, and force-baking
# RAM-to-Flash were downstream consequences of a legacy unmount-during-upgrade
# requirement that no longer exists. Default = hot-swap; the supervisor's
# post-restart bake handles persistence.
#
# Triggers full teardown (legacy "scorched earth" flow below) when:
#   (a) the .migration_required sentinel is present (per-PLG opt-in for ad-hoc
#       breaking changes that don't deserve a layout-version bump), OR
#   (b) the layout-version differs between the old install and the new payload.
#
# Pre-#941 versions never wrote src/.layout-version, so OLD_LAYOUT_VERSION
# defaults to "0" and triggers a one-time migration on first upgrade to v1 —
# correct because the supervisor architecture and overlay paths may have
# drifted across many releases on a long-lived install.
MIGRATION_NEEDED=0
# M4 fix (v2026.05.18.08): sanitize both versions to defend against an
# empty/whitespace-only/non-numeric .layout-version file that would otherwise
# cause a spurious scorched-earth teardown (e.g. `cat` of an empty file yields
# "" which fails the `!= "0"` test and trips the gate). Strip whitespace,
# default empty to "0", validate as pure integer.
_sanitize_layout_ver() {
    local v
    v=$(printf '%s' "${1:-}" | tr -d '[:space:]')
    [ -z "$v" ] && v="0"
    [[ "$v" =~ ^[0-9]+$ ]] || v="0"
    printf '%s' "$v"
}
NEW_LAYOUT_VERSION_RAW=$(cat "${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/.layout-version" 2>/dev/null || true)
NEW_LAYOUT_VERSION=$(_sanitize_layout_ver "$NEW_LAYOUT_VERSION_RAW")
OLD_LAYOUT_VERSION=$(_sanitize_layout_ver "${OLD_LAYOUT_VERSION:-0}")
MIGRATION_SENTINEL="${CONFIG_DIR:-/boot/config/plugins/unraid-aicliagents}/.migration_required"
if [ -f "$MIGRATION_SENTINEL" ]; then
    MIGRATION_NEEDED=1
    log_status "    > .migration_required sentinel present — running full teardown."
    rm -f "$MIGRATION_SENTINEL" 2>/dev/null || true
fi
if [ "$OLD_LAYOUT_VERSION" != "$NEW_LAYOUT_VERSION" ]; then
    MIGRATION_NEEDED=1
    log_status "    > Layout version changed ($OLD_LAYOUT_VERSION -> $NEW_LAYOUT_VERSION) — running full teardown."
fi

# --- Bug #1043 one-off: close pre-TMUX_TMPDIR terminal sessions ------------
# Versions before the private-tmux-socket change kept their sessions in the
# shared /tmp/tmux-<uid> socket; this version uses a plugin-private
# TMUX_TMPDIR, so any such sessions would be silently orphaned by the upgrade.
# Once only — gated by a sentinel, so it runs on exactly the first upgrade that
# carries this code (the transition off the shared socket) — gracefully close
# them: send Ctrl-C twice (the same safe close the UI uses) so each agent
# flushes its state into the home overlay, then kill the session. Runs before
# the hot-swap / full-teardown split so it covers both paths. `env -u
# TMUX_TMPDIR` targets the old default socket location. The sentinel is written
# unconditionally so this never runs again.
TMUX_MIGRATED="${CONFIG_DIR:-/boot/config/plugins/unraid-aicliagents}/.tmux-socket-migrated"
if [ ! -f "$TMUX_MIGRATED" ] && command -v tmux >/dev/null 2>&1; then
    _OLD_SESS=$(env -u TMUX_TMPDIR tmux ls -F '#S' 2>/dev/null | grep '^aicli-agent-' || true)
    if [ -n "$_OLD_SESS" ]; then
        log_status "    > Bug #1043 one-off: gracefully closing pre-upgrade terminal session(s) from the old tmux socket..."
        for _pass in 1 2; do
            printf '%s\n' "$_OLD_SESS" | while IFS= read -r _s; do
                [ -n "$_s" ] || continue
                env -u TMUX_TMPDIR tmux send-keys -t "$_s" C-c 2>/dev/null || true
            done
            sleep 1
        done
        sleep 2   # let agents finish flushing their state into the home overlay
        printf '%s\n' "$_OLD_SESS" | while IFS= read -r _s; do
            [ -n "$_s" ] || continue
            env -u TMUX_TMPDIR tmux kill-session -t "$_s" 2>/dev/null || true
        done
        log_ok "Pre-upgrade terminal sessions closed cleanly."
    fi
    touch "$TMUX_MIGRATED" 2>/dev/null || true
fi
# --------------------------------------------------------------------------

if [ "$MIGRATION_NEEDED" = "0" ] && [ "$UPGRADE_MODE" = "1" ]; then
    log_status "    > Hot-swap upgrade: sessions, overlays, and agent processes stay up."

    # WP #1277/#1278: NO pre-upgrade bake on the hot-swap path. ZRAM stays
    # mounted across a hot-swap, so the upgrade poses no data risk — the dirty
    # upper is exactly as safe as a second before, and persists to Flash via the
    # normal bake triggers (array-stop / shutdown / manual / low-RAM / scheduled).
    # The former pre-bake here was off-policy (an upgrade is not a bake trigger)
    # and, worse, ran commit_stack = bake + RECLAIM — and that reclaim was part of
    # the original conversation-loss mechanism (#1276). The genuine fix is
    # bake-confirmed reclaim (#1277, the reclaim can no longer destroy un-baked
    # data) + the consolidate completeness guard (#1278); with those in place the
    # post-restart supervisor bake is the safe, on-policy persistence path. The
    # earlier "power loss in the ~2-min window before the first post-restart bake"
    # rationale is no different from the inter-bake exposure the RAM-buffering
    # design already accepts everywhere — an upgrade is not special.
    #
    # The TEARDOWN / layout-bump path below (MIGRATION_NEEDED=1) still bakes,
    # because there ZRAM IS destroyed (agents killed, stacks unmounted) — a
    # mandatory flush, the same class as a shutdown/array-stop teardown event.

    lifecycle_log "info" "installer_cleanup" "installer_hotswap" "{\"layout_version\":\"$NEW_LAYOUT_VERSION\"}" 2>/dev/null || true
    log_ok "Pre-upgrade cleanup complete (hot-swap path — no bake; ZRAM persists in RAM)."
    exit 0
fi

# --- 0. ARCHITECTURE-AWARE PERSISTENCE (MANDATORY for Upgrades) ---
# D-344: Bake ZRAM dirty data to Flash before unmounting.
# Uses atomic_write_layer (Phase 2+): tempfile → fsync → verify → rename.
# Must NOT call old commit_stack.sh via PHP — it may have buggy fuser checks
# that unmount/remount while sessions are active, causing EIO crashes.
if [ "$UPGRADE_MODE" = "1" ]; then
    log_status "    > Persisting active user states before eviction..."

    ZRAM_UPPER="/tmp/unraid-aicliagents/zram_upper"

    # Strategy A: Atomic SquashFS bake from ZRAM upper (Phase 5: via atomic_write_layer).
    # Only bake dirs with real files (skip overlayfs whiteout-only dirs).
    EMHTTP_DEST_CLEANUP="/usr/local/emhttp/plugins/unraid-aicliagents"
    if ! declare -f atomic_write_layer >/dev/null 2>&1; then
        source "$EMHTTP_DEST_CLEANUP/src/scripts/storage/atomic_write_layer.sh" 2>/dev/null || true
    fi
    if [ -d "$ZRAM_UPPER/homes" ] && declare -f atomic_write_layer >/dev/null 2>&1; then
        lifecycle_log "info" "installer_cleanup" "installer_cleanup_start" "{}" 2>/dev/null || true
        for upper_dir in "$ZRAM_UPPER/homes"/*/upper; do
            [ -d "$upper_dir" ] || continue
            [ -z "$(find "$upper_dir" -type f 2>/dev/null | head -1)" ] && continue
            user=$(basename "$(dirname "$upper_dir")")
            PERSIST_DIR=$(home_persist_path "$user" 2>/dev/null || echo "")
            [ -z "$PERSIST_DIR" ] && PERSIST_DIR="${CONFIG_DIR:-/boot/config/plugins/unraid-aicliagents}"
            log_status "      [ZRAM] Baking home delta for $user..."
            DELTA_NAME=$(atomic_write_layer "home" "$user" "$PERSIST_DIR" "$upper_dir" "delta" 2>/dev/null)
            if [ -n "$DELTA_NAME" ]; then
                log_status "      [OK] Saved $DELTA_NAME to Flash."
                lifecycle_log "info" "installer_cleanup" "installer_zram_baked" "{\"user\":\"$user\",\"delta\":\"$DELTA_NAME\"}" 2>/dev/null || true
            else
                log_status "      [!!] Delta bake failed for $user."
            fi
        done
    fi

fi

# --- 1. SQLite WAL Checkpoint ---
# Merge WAL journals into main .db files before killing agent processes.
if command -v sqlite3 > /dev/null 2>&1; then
    DB_LIST=$(find /tmp/unraid-aicliagents/work -name "*.db" -o -name "*.sqlite" 2>/dev/null)
    DB_COUNT=$(echo "$DB_LIST" | grep -v "^$" | wc -l)
    if [ "$DB_COUNT" -gt 0 ]; then
        log_status "    > Checkpointing $DB_COUNT active SQLite database(s)..."
        for db in $DB_LIST; do
            sqlite3 "$db" "PRAGMA wal_checkpoint(TRUNCATE);" > /dev/null 2>&1 || true
        done
        log_ok "Database checkpoint complete."
    fi
fi

log_step "Evicting legacy processes for a clean upgrade..."

# --- 2. Kill Terminal Listeners (ttyd) ---
graceful_kill "ttyd.*aicliterm-"

# --- 4. Kill Active Agent tmux Sessions & Node Binaries ---
if command -v tmux > /dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    for _sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux -S "$_sock" kill-session -t "{}" > /dev/null 2>&1 || true
    done
fi
# Path-anchored pattern: only match node processes whose command line contains
# a plugin-owned path. Prevents the previous agent-name regex from accidentally
# matching unrelated host Node services (e.g. a `node /opt/factory-bot/...`).
# QEMU/libvirt processes never match — their cmdline has no "node" — but the
# _safe_filter_pids guard in graceful_kill is still there as belt and braces.
graceful_kill "node .*(unraid-aicliagents|/\\.aicli/)"
log_ok "Active processes terminated."

# --- 5. Unmount Storage Stacks ---
# D-295: Clear any existing loop mounts or OverlayFS stacks to prevent file locks during migration
# D-400: Use findmnt for reliable mount point parsing (mount | awk is fragile with spaces/options)
UMNT_COUNT=0
if command -v findmnt > /dev/null 2>&1; then
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        umount -l "$mnt" > /dev/null 2>&1 || true
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(findmnt -rn -o TARGET | grep -E "unraid-aicliagents|zram_upper" | tac)
else
    # Fallback: parse mount output more carefully (field 3 = mountpoint)
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        umount -l "$mnt" > /dev/null 2>&1 || true
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(mount | grep -E "unraid-aicliagents|zram_upper" | awk '$2=="on" {print $3}' | tac)
fi
[ "$UMNT_COUNT" -gt 0 ] && log_status "    > Unmounted $UMNT_COUNT active storage stack(s)."

# --- 5. Clear Runtime Locks & Temporary Scripts ---
rm -f /tmp/unraid-aicliagents/.init_done
rm -rf /var/run/aicli-sessions
rm -f /var/run/aicliterm-*.sock

# Legacy Btrfs/rsync code removed — only SquashFS architecture supported.
# Migration from Btrfs to SquashFS is handled by migrate-btrfs-to-squashfs.sh.

# --- 6. Runtime Cleanup (D-170) ---
# Remove the legacy .runtime directory inside the /agents mount point (now moved to plugin root)
if [ -d "$EMHTTP_DEST/agents/.runtime" ]; then
    log_step "Removing legacy runtime from agent storage..."
    rm -rf "$EMHTTP_DEST/agents/.runtime"
fi

log_ok "Pre-upgrade cleanup complete."

