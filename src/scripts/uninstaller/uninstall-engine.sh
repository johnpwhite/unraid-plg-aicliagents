#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/../storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# AICliAgents complete explicit-uninstall engine.
#
# This is NOT the upgrade cleanup path. Unraid `plugin update` executes the new
# PLG's install methods only; this engine is called by Method="remove" for a
# user-requested uninstall. Configuration and durable agent/home persistence are
# intentionally preserved so removing the UI cannot destroy conversations,
# credentials, workspaces, or settings.

set -u

NAME="${NAME:-unraid-aicliagents}"
CONFIG_DIR="${CONFIG_DIR:-/boot/config/plugins/$NAME}"
EMHTTP_DEST="${EMHTTP_DEST:-/usr/local/emhttp/plugins/$NAME}"
RUNTIME_DIR="${AICLI_RUNTIME_DIR:-${MOUNT_ROOT}}"
RUN_DIR="${AICLI_RUN_DIR:-/var/run}"
TMP_DIR="${AICLI_TMP_DIR:-/tmp}"
CRON_DIR="${AICLI_CRON_DIR:-/etc/cron.d}"
PROFILE_LINK="${AICLI_PROFILE_LINK:-/etc/profile.d/aicliagents.sh}"
LOCAL_BIN_DIR="${AICLI_LOCAL_BIN_DIR:-/usr/local/bin}"
NGINX_CONFIG="${AICLI_NGINX_CONFIG:-/etc/nginx/conf.d/unraid-aicliagents.conf}"
DYNAMIX_EVENTS="${AICLI_DYNAMIX_EVENTS_DIR:-/usr/local/emhttp/plugins/dynamix/events}"
PLUGIN_LOG="${AICLI_PLUGIN_LOG:-/var/log/plugins/${NAME}.plg}"
STOP_SCRIPT="${AICLI_STOP_SCRIPT:-$EMHTTP_DEST/src/scripts/user/stop-plugin.sh}"
CLEANUP_SCRIPT="${AICLI_CLEANUP_SCRIPT:-$EMHTTP_DEST/src/scripts/uninstaller/cleanup.sh}"
LOG_FILE="${AICLI_UNINSTALL_LOG:-$CONFIG_DIR/uninstall.log}"

# The PLG caller pre-opens fd 3 for real-time status. Standalone execution and
# hermetic tests do not, so make the same contract available without failing.
if ! { true >&3; } 2>/dev/null; then
    exec 3>&1
fi
mkdir -p "$CONFIG_DIR" 2>/dev/null || true

log_status() {
    printf '%s\n' "$1" >&3
    printf '[%s] %s\n' "$(date +%T)" "$1" >> "$LOG_FILE" 2>/dev/null || true
}
export -f log_status
export LOG_FILE

remove_link_into_plugin() {
    local link="$1" target resolved
    [ -L "$link" ] || return 0
    target=$(readlink "$link" 2>/dev/null || true)
    resolved=$(readlink -f "$link" 2>/dev/null || true)
    case "$target" in
        "$EMHTTP_DEST"|"$EMHTTP_DEST"/*) rm -f "$link" ; return 0 ;;
    esac
    case "$resolved" in
        "$EMHTTP_DEST"|"$EMHTTP_DEST"/*) rm -f "$link" ;;
    esac
}

log_status "--- AICliAgents Complete Uninstall Start ---"

# Phase 1: the data-safe stop is authoritative. It captures resume identifiers,
# stops the supervisor/retry loops/agents/secret-service/tmux, bakes dirty home
# ZRAM data to persistence, and unmounts overlays + loop devices top-down.
if [ -f "$STOP_SCRIPT" ]; then
    log_status "[1/5] Gracefully stopping sessions and persisting dirty home data..."
    if ! bash "$STOP_SCRIPT"; then
        log_status "WARN: graceful stop reported an error; running the residual sweep."
    fi
else
    log_status "WARN: graceful stop utility is missing; running the residual sweep."
fi

# Phase 2: independent cleanup catches lost-pidfile supervisors and partial
# stop paths. It MUST finish before the PHP/source tree is removed.
log_status "[2/5] Verifying all plugin processes are stopped..."
if [ ! -f "$CLEANUP_SCRIPT" ]; then
    log_status "ERROR: residual cleanup utility is missing; refusing to remove plugin source."
    exit 1
fi
if ! AICLI_CLEANUP_KEEP_RUNTIME=1 \
     AICLI_RUNTIME_DIR="$RUNTIME_DIR" AICLI_RUN_DIR="$RUN_DIR" \
     EMHTTP_DEST="$EMHTTP_DEST" bash "$CLEANUP_SCRIPT"; then
    log_status "ERROR: a plugin process survived cleanup; source files were not removed."
    exit 1
fi

# Phase 3: residual mount/loop sweep. The graceful stop normally handled these;
# this pass covers interrupted installs and historical layouts.
log_status "[3/5] Detaching residual storage mounts and loop devices..."
# #512: a REAL umount only, tried 3 times. Never `umount -l`: the server stays up
# after an uninstall, and a lazily detached layer mount keeps its loop on the
# layer file. A mount that stays busy is kept and named; Phase 5 then leaves the
# folder that holds it (a plain rm -rf would delete through the live mount).
UNINSTALL_KEPT_MOUNTS=0
while IFS= read -r mnt; do
    [ -n "$mnt" ] || continue
    _un_ok=0
    for _un_i in 1 2 3; do
        if umount "$mnt" 2>/dev/null || ! mountpoint -q "$mnt" 2>/dev/null; then _un_ok=1; break; fi
        sleep "${AICLI_UMOUNT_RETRY_S:-1}"
    done
    if [ "$_un_ok" -ne 1 ]; then
        UNINSTALL_KEPT_MOUNTS=$((UNINSTALL_KEPT_MOUNTS + 1))
        log_status "WARNING: $mnt is still busy; kept mounted (never lazy-unmounted, #512)."
    fi
done < <(awk '{print $2}' /proc/mounts 2>/dev/null \
    | grep -E '^'"$RUNTIME_DIR"'(/|$)|^'"$EMHTTP_DEST"'/agents(/|$)' \
    | sort -r || true)
for loop in $(losetup -a 2>/dev/null | grep -E "unraid-aicliagents|$RUNTIME_DIR|$EMHTTP_DEST" | cut -d: -f1); do
    losetup -d "$loop" 2>/dev/null || true
done
# R-091: initialize_zram.sh sized a zram device (4 GB limit) for the home upper
# folders. After the last unmount nothing uses it, but its RAM stays allocated
# until a reboot. Reset the device that carries our label, and only that one
# (zram0 may belong to another plugin). A device that is still mounted is kept.
if command -v zramctl >/dev/null 2>&1; then
    for _un_z in "${AICLI_SYS_BLOCK:-/sys/block}"/zram*; do
        [ -d "$_un_z" ] || continue
        _un_zn="$(basename "$_un_z")"
        [ "$(blkid -s LABEL -o value "/dev/$_un_zn" 2>/dev/null)" = "AICLI_ZRAM" ] || continue
        if grep -q "^/dev/$_un_zn " "${AICLI_PROC_MOUNTS:-/proc/mounts}" 2>/dev/null; then
            log_status "WARNING: /dev/$_un_zn is still mounted; its RAM stays allocated until a reboot."
            continue
        fi
        if zramctl -r "/dev/$_un_zn" 2>/dev/null; then
            log_status "Released the RAM of /dev/$_un_zn (zram)."
        else
            log_status "WARNING: could not reset /dev/$_un_zn; its RAM stays allocated until a reboot."
        fi
    done
fi

# Phase 4: remove every host integration installed outside the plugin tree.
log_status "[4/5] Removing cron, shell, command, event, and nginx integrations..."
rm -f "$CRON_DIR/unraid-aicliagents.agent-check"
rm -f "$CRON_DIR/unraid-aicliagents.health-check"
if [ "${AICLI_SKIP_SERVICE_RELOADS:-0}" != "1" ] && [ -x /usr/local/sbin/update_cron ]; then
    /usr/local/sbin/update_cron >/dev/null 2>&1 || true
fi

rm -f "$PROFILE_LINK"
for link in "$LOCAL_BIN_DIR"/*; do
    remove_link_into_plugin "$link"
done

rm -f "$DYNAMIX_EVENTS/stopping/aicli_sync"
rm -f "$DYNAMIX_EVENTS/stopping_array/aicli_sync"
rm -f "$DYNAMIX_EVENTS/disks_mounted/aicli_restore"

nginx_changed=0
if [ -e "$NGINX_CONFIG" ] || [ -L "$NGINX_CONFIG" ]; then
    rm -f "$NGINX_CONFIG"
    nginx_changed=1
fi
if [ "$nginx_changed" -eq 1 ] \
   && [ "${AICLI_SKIP_SERVICE_RELOADS:-0}" != "1" ] \
   && [ -x /etc/rc.d/rc.nginx ]; then
    # #168: DETACH + DELAY the reload. This uninstall runs inside the Unraid
    # plugin-manager's `plugin remove … nchan` wrapper, which streams progress to
    # the browser THROUGH nginx. A synchronous reload severs that stream — the
    # dialog freezes at [4/5] and the wrapper hangs on the dead pipe (zombie
    # child + "operation continues in background" banner). Detached + a few
    # seconds later, the wrapper finishes streaming [5/5] and reaps cleanly first.
    # #337: the shared helper also closes the wrapper's output fd 3, which a
    # plain `setsid ... &` kept open until the reload finished.
    if ! bash "$EMHTTP_DEST/src/scripts/aicli-detach.sh" -- bash -c 'sleep 3; /etc/rc.d/rc.nginx reload >/dev/null 2>&1' >/dev/null 2>&1; then
        setsid bash -c 'sleep 3; /etc/rc.d/rc.nginx reload >/dev/null 2>&1' >/dev/null 2>&1 </dev/null 3>&- 4>&- 9>&- &
    fi
fi

# Phase 5: runtime and deployed UI/source removal. Config + persistence remain.
log_status "[5/5] Removing runtime state and deployed plugin files..."
# #512: never delete a folder that still holds a mount (a kept busy mount from
# Phase 3): rm -rf would go into the live mount and delete user data.
_rm_tree_unless_mounted() {
    local d="$1"
    [ -e "$d" ] || return 0
    if awk -v d="$d" '$2 == d || index($2, d "/") == 1 { f = 1 } END { exit !f }' "${AICLI_PROC_MOUNTS:-/proc/mounts}" 2>/dev/null; then
        log_status "WARNING: kept $d, because a mount is still inside it (#512). Remove it after a reboot."
        return 1
    fi
    rm -rf "$d"
}
_rm_tree_unless_mounted "$RUNTIME_DIR"
rm -rf "$RUN_DIR/aicli-sessions"
rm -f "$RUN_DIR"/aicliterm-*.sock
rm -f "$RUN_DIR"/unraid-aicliagents-*.pid
rm -f "$RUN_DIR"/unraid-aicliagents-*.lock
rm -f "$RUN_DIR"/unraid-aicliagents-*.chatid
rm -f "$RUN_DIR"/unraid-aicliagents-*.agentid
rm -f "$RUN_DIR/aicli-supervisor.pid" "$RUN_DIR/aicli-supervisor.tick" "$RUN_DIR/aicli-supervisor.work.json"
rm -f "$TMP_DIR"/aicli-*.sh "$TMP_DIR"/aicli-*.page "$TMP_DIR/aicli-src.tar.gz" "$TMP_DIR"/ttyd-aicli-*.log
# The installer payload is cached on flash so a reboot can install without network
# (#125). It is plugin-owned, not user data, so it goes with the uninstall — unlike
# the *.cfg files and persistence layers, which are deliberately preserved below.
rm -f "$CONFIG_DIR"/aicli-*.sh "$CONFIG_DIR"/aicli-*.page "$CONFIG_DIR"/aicli-src.tar.gz "$CONFIG_DIR"/aicli-src-*.tar.gz

if [ -L "$EMHTTP_DEST" ]; then
    rm -f "$EMHTTP_DEST"
elif [ -d "$EMHTTP_DEST" ]; then
    _rm_tree_unless_mounted "$EMHTTP_DEST"
fi
rm -f "$PLUGIN_LOG"

log_status "Uninstall complete: processes, mounts, host integrations, runtime state, and WebGUI files removed."
log_status "Preserved user data: $CONFIG_DIR/*.cfg and configured persistence layers."
exit 0
