#!/bin/bash
_INSTALLER_RESOLVER="$(dirname "${BASH_SOURCE[0]}")/../storage/resolve_paths.sh"
[ -f "$_INSTALLER_RESOLVER" ] || _INSTALLER_RESOLVER="${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/scripts/storage/resolve_paths.sh"
source "$_INSTALLER_RESOLVER" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
_SOCKET_SAFETY="$(dirname "${BASH_SOURCE[0]}")/../tmux-socket-safety.sh"
[ -f "$_SOCKET_SAFETY" ] || _SOCKET_SAFETY="${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/scripts/tmux-socket-safety.sh"
source "$_SOCKET_SAFETY" || { echo 'tmux-socket-safety.sh missing' >&2; exit 1; }
# AICliAgents Installer: Pre-Upgrade Process Eviction & Session Backup (v41)

# Bug #1043: route tmux at the plugin-private socket dir (see aicli-shell.sh).
export TMUX_TMPDIR="${MOUNT_ROOT}/tmux"

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
    pids=$(pgrep -f "$pattern" 2>/dev/null | grep -vx "$$" || true)
    pids=$(_safe_filter_pids "$pids")
    if [ -n "$pids" ]; then
        echo "$pids" | xargs -r kill -15 > /dev/null 2>&1 || true
        sleep 2
        pids=$(pgrep -f "$pattern" 2>/dev/null | grep -vx "$$" || true)
        pids=$(_safe_filter_pids "$pids")
        if [ -n "$pids" ]; then
            echo "$pids" | xargs -r kill -9 > /dev/null 2>&1 || true
        fi
    fi
}

# --- Layout-version marker on flash (HARD_STOP_RESILIENCE R2) ---
# The old layout version used to live only in RAM (src/.layout-version under the
# plugin directory, snapshotted by the PLG into OLD_LAYOUT_VERSION). After a
# reboot the RAM copy is gone, the PLG snapshots "0", and every boot looked like
# a layout change, which forced the destructive full teardown. A copy of the
# marker is now kept on flash. When the RAM snapshot is missing ("0"), the flash
# copy is used as the OLD layout version. A real difference between OLD and NEW
# still forces the full teardown.
#
# First run after this change: no flash copy exists yet, so nothing changes.
# OLD stays "0" and the behaviour is exactly as before (one full teardown). The
# flash copy is written at the end of that run, so later reboots are quiet.
_sanitize_layout_ver() {
    local v
    v=$(printf '%s' "${1:-}" | tr -d '[:space:]')
    [ -z "$v" ] && v="0"
    [[ "$v" =~ ^[0-9]+$ ]] || v="0"
    printf '%s' "$v"
}
_layout_flash_file() {
    printf '%s/.layout-version' "${AICLI_LAYOUT_MARKER_DIR:-${CONFIG_DIR:-${PLUGIN_BASE}}}"
}
# Sets OLD_LAYOUT_VERSION (sanitised). Falls back to the flash copy when the
# RAM snapshot is missing or "0".
_resolve_old_layout_version() {
    local f flash_raw flash_v
    OLD_LAYOUT_VERSION=$(_sanitize_layout_ver "${OLD_LAYOUT_VERSION:-0}")
    if [ "$OLD_LAYOUT_VERSION" = "0" ]; then
        f=$(_layout_flash_file)
        if [ -f "$f" ]; then
            flash_raw=$(cat "$f" 2>/dev/null || true)
            flash_v=$(_sanitize_layout_ver "$flash_raw")
            if [ "$flash_v" != "0" ]; then
                OLD_LAYOUT_VERSION="$flash_v"
                log_status "    > Layout marker in RAM is missing; using the copy on flash (v$flash_v)."
            fi
        fi
    fi
}
# Write the flash copy: temp file + mv, so a power cut never leaves half a file.
_write_layout_flash_marker() {
    local v f tmp
    v=$(_sanitize_layout_ver "${1:-}")
    [ "$v" = "0" ] && return 0
    f=$(_layout_flash_file)
    mkdir -p "$(dirname "$f")" 2>/dev/null || return 0
    tmp="$f.tmp.$$"
    if printf '%s\n' "$v" > "$tmp" 2>/dev/null && mv -f "$tmp" "$f" 2>/dev/null; then
        return 0
    fi
    rm -f "$tmp" 2>/dev/null || true
    return 0
}

# --- SQLite WAL checkpoint, made safe (HARD_STOP_RESILIENCE R2) ---
# Rules: never touch a database a process holds open; never checkpoint a
# database that fails quick_check (a checkpoint can write damaged pages into the
# main file); every sqlite3 call has a timeout; never abort the caller; skip the
# whole section when a bake or consolidate holds a storage lock.
# Bake and consolidate use one lock file per entity: /var/run/aicli-bake-<type>-<id>.lock
# (fd 9 in bake, fd 8 in consolidate). We try each one without blocking.
_sqlite_checkpoint_section() {
    local root="${1:-${MOUNT_ROOT}/work}"
    local lockdir="${AICLI_BAKE_LOCK_DIR:-/var/run}"
    command -v sqlite3 >/dev/null 2>&1 || return 0

    local -a lock_fds=()
    local lf fd lock_held=""
    if command -v flock >/dev/null 2>&1; then
        for lf in "$lockdir"/aicli-bake-*.lock; do
            [ -e "$lf" ] || continue
            fd=""
            { exec {fd}>>"$lf"; } 2>/dev/null || continue
            if flock -n "$fd" 2>/dev/null; then
                lock_fds+=("$fd")
            else
                lock_held="$lf"
                { exec {fd}>&-; } 2>/dev/null || true
                break
            fi
        done
    fi
    if [ -n "$lock_held" ]; then
        for fd in "${lock_fds[@]}"; do { exec {fd}>&-; } 2>/dev/null || true; done
        log_status "    > SQLite checkpoint skipped: storage lock held (${lock_held##*/}); a bake or consolidate is running."
        return 0
    fi

    local have_fuser=0
    command -v fuser >/dev/null 2>&1 && have_fuser=1
    local n_ok=0 n_held=0 n_damaged=0 n_timeout=0 n_error=0 n_total=0
    local db out rc

    while IFS= read -r -d '' db; do
        n_total=$((n_total + 1))
        if [ "$have_fuser" = "1" ] && fuser -s "$db" >/dev/null 2>&1; then
            n_held=$((n_held + 1))
            log_status "      [SQLite] skipped-held: $db"
            continue
        fi
        out=$(timeout 10 sqlite3 -readonly "$db" 'PRAGMA quick_check(1);' 2>&1)
        rc=$?
        if [ "$rc" = "124" ] || [ "$rc" = "137" ]; then
            n_timeout=$((n_timeout + 1))
            log_status "      [SQLite] timeout (quick_check): $db"
            continue
        fi
        if [ "$out" != "ok" ]; then
            if printf '%s' "$out" | grep -qiE 'database is locked|database table is locked|busy'; then
                n_error=$((n_error + 1))
                log_status "      [SQLite] error (locked): $db"
            else
                n_damaged=$((n_damaged + 1))
                log_status "      [SQLite] damaged (left untouched): $db"
            fi
            continue
        fi
        out=$(timeout 20 sqlite3 "$db" 'PRAGMA wal_checkpoint(TRUNCATE);' 2>&1)
        rc=$?
        if [ "$rc" = "124" ] || [ "$rc" = "137" ]; then
            n_timeout=$((n_timeout + 1))
            log_status "      [SQLite] timeout (checkpoint): $db"
        elif [ "$rc" != "0" ]; then
            n_error=$((n_error + 1))
            log_status "      [SQLite] error (checkpoint rc=$rc): $db"
        else
            n_ok=$((n_ok + 1))
            log_status "      [SQLite] ok: $db"
        fi
    done < <(find "$root" -type f \( -name '*.db' -o -name '*.sqlite' \) -print0 2>/dev/null || true)

    for fd in "${lock_fds[@]}"; do { exec {fd}>&-; } 2>/dev/null || true; done
    log_status "    > SQLite checkpoint summary: $n_total database(s): ok=$n_ok skipped-held=$n_held damaged=$n_damaged timeout=$n_timeout error=$n_error"
    return 0
}

# HARD_STOP_RESILIENCE R6: a plugin update must not stop storage work while a
# save is running. A save (bake) or a consolidate holds one lock file per entity:
# /var/run/aicli-bake-<type>-<id>.lock. This function waits until no such lock is
# held. It polls without blocking. It gives up after AICLI_BAKE_WAIT_S seconds
# (default 600) and the caller goes on. It never fails the caller.
# AICLI_BAKE_WAIT_POLL_S (default 2) sets the poll step; tests make it small.
_wait_for_running_save() {
    local lockdir="${AICLI_BAKE_LOCK_DIR:-/var/run}"
    local limit="${AICLI_BAKE_WAIT_S:-600}"
    local step="${AICLI_BAKE_WAIT_POLL_S:-2}"
    command -v flock >/dev/null 2>&1 || return 0
    case "$limit" in ''|*[!0-9]*) limit=600 ;; esac
    case "$step" in ''|*[!0-9.]*) step=2 ;; esac

    local start=$SECONDS held="" announced=0 lf fd
    while :; do
        held=""
        for lf in "$lockdir"/aicli-bake-*.lock; do
            [ -e "$lf" ] || continue
            fd=""
            { exec {fd}>>"$lf"; } 2>/dev/null || continue
            if flock -n "$fd" 2>/dev/null; then
                { exec {fd}>&-; } 2>/dev/null || true
            else
                { exec {fd}>&-; } 2>/dev/null || true
                held="${lf##*/}"
                break
            fi
        done
        [ -z "$held" ] && break
        if [ "$announced" -eq 0 ]; then
            log_status "    > waiting for a running save ($held)" || true
            announced=1
        fi
        if [ $((SECONDS - start)) -ge "$limit" ]; then
            log_status "    > gave up waiting for a running save ($held) after ${limit}s; going on." || true
            return 0
        fi
        sleep "$step" || true
    done
    if [ "$announced" -eq 1 ]; then
        log_status "    > running save finished; waited $((SECONDS - start))s." || true
    fi
    return 0
}

# Sourcing this file (tests) loads the functions above and stops here; running
# it (the PLG) continues into the teardown flow below.
if [ "${BASH_SOURCE[0]}" != "$0" ]; then
    return 0
fi

# --- 0. WAIT FOR A RUNNING SAVE (R6) ---
# Runs before the first stop or kill, in both the hot-swap and full-teardown paths.
_wait_for_running_save || true

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
# default empty to "0", validate as pure integer (_sanitize_layout_ver, defined above).
NEW_LAYOUT_VERSION_RAW=$(cat "${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/.layout-version" 2>/dev/null || true)
NEW_LAYOUT_VERSION=$(_sanitize_layout_ver "$NEW_LAYOUT_VERSION_RAW")
_resolve_old_layout_version
MIGRATION_SENTINEL="${CONFIG_DIR:-${PLUGIN_BASE}}/.migration_required"
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
TMUX_MIGRATED="${CONFIG_DIR:-${PLUGIN_BASE}}/.tmux-socket-migrated"
if [ "$MIGRATION_NEEDED" = "1" ] && [ ! -f "$TMUX_MIGRATED" ] && command -v tmux >/dev/null 2>&1; then
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
    _write_layout_flash_marker "$NEW_LAYOUT_VERSION"
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

    ZRAM_UPPER="${MOUNT_ROOT}/zram_upper"

    # Strategy A: Atomic SquashFS bake from ZRAM upper (Phase 5: via atomic_write_layer).
    # Only bake dirs with real files (skip overlayfs whiteout-only dirs).
    EMHTTP_DEST_CLEANUP="/usr/local/emhttp/plugins/unraid-aicliagents"
    if ! declare -f atomic_write_layer >/dev/null 2>&1; then
        source "$EMHTTP_DEST_CLEANUP/src/scripts/storage/atomic_write_layer.sh" 2>/dev/null || true
    fi
    if ! declare -f manifest_record_layer >/dev/null 2>&1; then
        source "$EMHTTP_DEST_CLEANUP/src/scripts/storage/manifest_write.sh" 2>/dev/null || true
    fi
    # #372 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins"): bake
    # the upper each home's mount REALLY uses (zram first, then disk). Without
    # the new resolve_paths.sh (an older deployed copy): the zram folders.
    _cleanup_home_uppers() {
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
    _CLEANUP_BAKE_LOCK_FD=""
    _cleanup_take_home_bake_lock() { # <home id> -> takes the shared bake lock, bounded wait
        local id="${1:-}" lock_id lock_dir lock_path limit deadline fd
        _CLEANUP_BAKE_LOCK_FD=""
        lock_id="${id//[^a-zA-Z0-9_-]/_}"
        lock_dir="${AICLI_BAKE_LOCK_DIR:-/var/run}"
        lock_path="$lock_dir/aicli-bake-home-$lock_id.lock"
        command -v flock >/dev/null 2>&1 || return 1
        mkdir -p "$lock_dir" 2>/dev/null || return 1
        { exec {fd}>>"$lock_path"; } 2>/dev/null || return 1

        limit="${AICLI_BAKE_LOCK_WAIT_S:-30}"
        case "$limit" in ''|*[!0-9]*) limit=30 ;; esac
        [ "$limit" -le 120 ] || limit=120
        deadline=$((SECONDS + limit))
        while :; do
            if flock -n "$fd" 2>/dev/null; then
                _CLEANUP_BAKE_LOCK_FD="$fd"
                return 0
            fi
            [ "$SECONDS" -lt "$deadline" ] || break
            sleep 0.25 || true
        done
        { exec {fd}>&-; } 2>/dev/null || true
        log_status "      [!!] Could not take the home/$id bake lock after ${limit}s."
        return 1
    }
    _cleanup_release_home_bake_lock() {
        local fd="${_CLEANUP_BAKE_LOCK_FD:-}"
        [ -n "$fd" ] || return 0
        { exec {fd}>&-; } 2>/dev/null || true
        _CLEANUP_BAKE_LOCK_FD=""
    }
    if declare -f atomic_write_layer >/dev/null 2>&1; then
        lifecycle_log "info" "installer_cleanup" "installer_cleanup_start" "{}" 2>/dev/null || true
        while IFS=$'\t' read -r user upper_dir; do
            [ -n "$user" ] && [ -d "$upper_dir" ] || continue
            # Use the same per-home lock as storagectl bakes. The earlier
            # drain is best effort; taking this lock closes the race with a
            # save that starts after that scan.
            _cleanup_take_home_bake_lock "$user" || continue
            if [ -z "$(find "$upper_dir" -type f 2>/dev/null | head -1)" ]; then
                _cleanup_release_home_bake_lock
                continue
            fi
            PERSIST_DIR=$(home_persist_path "$user" 2>/dev/null || echo "")
            [ -z "$PERSIST_DIR" ] && PERSIST_DIR="${CONFIG_DIR:-${PLUGIN_BASE}}"
            log_status "      [ZRAM] Baking home delta for $user..."
            DELTA_NAME=$(atomic_write_layer "home" "$user" "$PERSIST_DIR" "$upper_dir" "delta" 2>/dev/null)
            if [ -n "$DELTA_NAME" ]; then
                # R-081: record the delta in the manifest at once, as the stopping
                # hook does. Without the entry the layer is untracked until a
                # reconcile finds it.
                if declare -f manifest_record_layer >/dev/null 2>&1; then
                    manifest_record_layer "home" "$user" "$PERSIST_DIR" "$DELTA_NAME" \
                        || log_status "      [!!] Manifest record failed for $DELTA_NAME (reconcile will recover)."
                fi
                log_status "      [OK] Saved $DELTA_NAME to Flash."
                lifecycle_log "info" "installer_cleanup" "installer_zram_baked" "{\"user\":\"$user\",\"delta\":\"$DELTA_NAME\"}" 2>/dev/null || true
            else
                log_status "      [!!] Delta bake failed for $user."
            fi
            _cleanup_release_home_bake_lock
        done < <(_cleanup_home_uppers)
    fi

fi

# --- 1. SQLite WAL Checkpoint (safe: lock-aware, quick_check first, timeouts) ---
# Merge WAL journals into main .db files before killing agent processes.
_sqlite_checkpoint_section "${MOUNT_ROOT}/work" || true

log_step "Evicting legacy processes for a clean upgrade..."

# --- 2. Kill Terminal Listeners (ttyd) ---
graceful_kill "ttyd.*aicliterm-"

# --- 4. Kill Active Agent tmux Sessions & Node Binaries ---
# Second review, finding 1: root never runs a tmux client against a socket that the run user (aicli-run) can write. A fake server
# on such a socket can order the client to start a program as root. Such a socket is skipped here; the processes of the run user
# are ended by user id instead. When this script runs as the run user itself, nothing is skipped.
_run_uid="$(id -u aicli-run 2>/dev/null || true)"
if command -v tmux > /dev/null 2>&1; then
    # Non-root audit: iterate every per-uid tmux socket so non-root sessions
    # get killed too.
    # Bug #141: sessions now each own a private server under s-<sid>/tmux-<uid>;
    # pre-#141 sessions may still be live on the legacy shared socket. Cover both.
    # #929: the sockets of a restricted workspace are in the root-only ${MOUNT_ROOT}/run-tmux. The safety check skips them (the
    # folder and the socket belong to the run user); the kill by user id below ends those servers.
    for _sock in "${MOUNT_ROOT}"/tmux/s-*/tmux-*/default \
                 "${MOUNT_ROOT}"/run-tmux/s-*/tmux-*/default \
                 "${MOUNT_ROOT}"/tmux/tmux-*/default; do
        [ -S "$_sock" ] || continue
        aicli_tmux_socket_skip_for_root "$_sock" "$_run_uid" && continue
        tmux -S "$_sock" ls -F '#S' 2>/dev/null | grep "^aicli-agent-" | xargs -r -I {} tmux -S "$_sock" kill-session -t "{}" > /dev/null 2>&1 || true
    done
fi
# Every process of the run user ends here, counted by user id (not by tmux session).
[ -n "$_run_uid" ] && { pkill -KILL -U "$_run_uid" 2>/dev/null; pkill -KILL -u "$_run_uid" 2>/dev/null; } || true
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
# #512: a REAL umount only, tried 3 times. Never `umount -l`: an upgrade runs
# while the server stays up, and a lazily detached layer mount keeps its loop on
# the layer file, so a later deleted layer keeps its space on the flash until a
# reboot. A mount that stays busy is kept and named in the log; the new install
# reuses it (mount_stack skips a layer that is already mounted).
_cleanup_umount() {
    local m="$1" i
    for i in 1 2 3; do
        umount "$m" > /dev/null 2>&1 && return 0
        mountpoint -q "$m" 2>/dev/null || return 0
        sleep "${AICLI_UMOUNT_RETRY_S:-1}"
    done
    log_status "    > Kept busy mount $m (never lazy-unmounted, #512)."
    return 1
}
UMNT_COUNT=0
UMNT_KEPT=0
if command -v findmnt > /dev/null 2>&1; then
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        _cleanup_umount "$mnt" || { UMNT_KEPT=$((UMNT_KEPT + 1)); continue; }
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(findmnt -rn -o TARGET | grep -E "unraid-aicliagents|zram_upper" | tac)
else
    # Fallback: parse mount output more carefully (field 3 = mountpoint)
    while IFS= read -r mnt; do
        [ -z "$mnt" ] && continue
        _cleanup_umount "$mnt" || { UMNT_KEPT=$((UMNT_KEPT + 1)); continue; }
        UMNT_COUNT=$((UMNT_COUNT + 1))
    done < <(mount | grep -E "unraid-aicliagents|zram_upper" | awk '$2=="on" {print $3}' | tac)
fi
[ "$UMNT_COUNT" -gt 0 ] && log_status "    > Unmounted $UMNT_COUNT active storage stack(s)."
[ "$UMNT_KEPT" -gt 0 ] && log_status "    > Kept $UMNT_KEPT busy mount(s) (#512)."

# --- 5. Clear Runtime Locks & Temporary Scripts ---
rm -f "${MOUNT_ROOT}/.init_done"
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

_write_layout_flash_marker "$NEW_LAYOUT_VERSION"
log_ok "Pre-upgrade cleanup complete."
