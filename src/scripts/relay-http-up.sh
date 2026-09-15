#!/bin/bash
# relay-http-up.sh — idempotent bring-up of the plugin-owned Relay HTTP listener
# (Forgejo #113 / docs/specs/RELAY_HTTP_TRANSPORT.md).
#
# Modelled on secret-service-up.sh. The listener is a separate process from the
# Unraid webGUI on purpose: Unraid gives plugins no nginx include hook (#114),
# and patching its locations.conf could take the whole webGUI down.
#
# Usage:  relay-http-up.sh start [bind] [port]
#         relay-http-up.sh stop
#         relay-http-up.sh status
# Exit:   0 on success; non-zero if the listener could not be brought up.
set -u

ACTION="${1:-start}"
BIND="${2:-0.0.0.0}"
PORT="${3:-8237}"

# Pin the generation the same way the Relay MCP command does (#112), so an
# activation cannot swap the server out from under a running listener.
PLUGIN_SRC="${AICLI_PLUGIN_SRC:-$(readlink -f /usr/local/emhttp/plugins/unraid-aicliagents/src 2>/dev/null \
    || echo /usr/local/emhttp/plugins/unraid-aicliagents/src)}"
SERVER="$PLUGIN_SRC/scripts/relay-http-server.php"

# Runtime state lives on /tmp, never /mnt/user: a blocking flock over shfs can
# starve the FUSE worker pool and freeze every share on the host.
RTDIR="${AICLI_RELAY_HTTP_RUNTIME:-/tmp/unraid-aicliagents/relay-http}"
PIDFILE="$RTDIR/listener.pid"
LOG="$RTDIR/listener.log"

mkdir -p "$RTDIR" 2>/dev/null
chmod 0700 "$RTDIR" 2>/dev/null

_alive() {
    local p
    p=$(cat "$PIDFILE" 2>/dev/null) || return 1
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null
}

case "$ACTION" in
  status)
    _alive && { echo "running $(cat "$PIDFILE")"; exit 0; }
    echo "stopped"; exit 1
    ;;
  stop)
    if _alive; then
        p=$(cat "$PIDFILE")
        kill -TERM "$p" 2>/dev/null || true
        for _ in $(seq 1 10); do kill -0 "$p" 2>/dev/null || break; sleep 0.2; done
        kill -0 "$p" 2>/dev/null && kill -KILL "$p" 2>/dev/null || true
    fi
    rm -f "$PIDFILE" 2>/dev/null
    exit 0
    ;;
  start) ;;
  *) echo "usage: relay-http-up.sh start|stop|status [bind] [port]" >&2; exit 2 ;;
esac

[ -f "$SERVER" ] || { echo "relay-http: server missing: $SERVER" >&2; exit 1; }

# Fast path — already up.
_alive && exit 0

# Serialise bring-up so two callers cannot race two listeners onto one port.
# Non-blocking with a bounded wait: never a bare blocking flock.
exec 9>"$RTDIR/.up.lock"
_locked=0
for _ in $(seq 1 25); do
    if flock -n 9; then _locked=1; break; fi
    sleep 0.2
done
[ "$_locked" = "1" ] || { echo "relay-http: could not acquire bring-up lock" >&2; exit 1; }

# Re-check under the lock — another caller may have just started it.
_alive && exit 0

nohup php "$SERVER" "$BIND" "$PORT" >>"$LOG" 2>&1 &
echo $! > "$PIDFILE"

# Confirm it survived startup rather than reporting a pid that already exited
# (a bad cert or a taken port fails immediately).
for _ in $(seq 1 15); do
    _alive || break
    if grep -q "listening on" "$LOG" 2>/dev/null; then exit 0; fi
    sleep 0.2
done

if ! _alive; then
    rm -f "$PIDFILE" 2>/dev/null
    echo "relay-http: listener exited during startup — see $LOG" >&2
    tail -3 "$LOG" 2>/dev/null >&2
    exit 1
fi
exit 0
