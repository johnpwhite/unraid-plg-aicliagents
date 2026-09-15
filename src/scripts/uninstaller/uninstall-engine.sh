#!/bin/bash
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
RUNTIME_DIR="${AICLI_RUNTIME_DIR:-/tmp/unraid-aicliagents}"
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
while IFS= read -r mnt; do
    [ -n "$mnt" ] || continue
    umount -l "$mnt" 2>/dev/null || true
done < <(awk '{print $2}' /proc/mounts 2>/dev/null \
    | grep -E '^'"$RUNTIME_DIR"'(/|$)|^'"$EMHTTP_DEST"'/agents(/|$)' \
    | sort -r || true)
for loop in $(losetup -a 2>/dev/null | grep -E "unraid-aicliagents|$RUNTIME_DIR|$EMHTTP_DEST" | cut -d: -f1); do
    losetup -d "$loop" 2>/dev/null || true
done

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
    setsid bash -c 'sleep 3; /etc/rc.d/rc.nginx reload >/dev/null 2>&1' >/dev/null 2>&1 </dev/null 9>&- &
fi

# Phase 5: runtime and deployed UI/source removal. Config + persistence remain.
log_status "[5/5] Removing runtime state and deployed plugin files..."
rm -rf "$RUNTIME_DIR"
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
    rm -rf "$EMHTTP_DEST"
fi
rm -f "$PLUGIN_LOG"

log_status "Uninstall complete: processes, mounts, host integrations, runtime state, and WebGUI files removed."
log_status "Preserved user data: $CONFIG_DIR/*.cfg and configured persistence layers."
exit 0
