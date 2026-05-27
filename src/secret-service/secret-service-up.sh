#!/bin/bash
# secret-service-up.sh — idempotent per-user bring-up of the AICliAgents Secret
# Service (Bug #1042 / docs/specs/SECRET_SERVICE.md).
#
# Starts a private D-Bus session bus plus the keyring daemon for the calling
# user, reused across that user's agent sessions. Keyring-using agents (e.g.
# Antigravity CLI) can then persist their auth token instead of re-prompting
# every session.
#
# Usage:  secret-service-up.sh <user> <home_dir>
# Output: the session-bus address on stdout (unix:path=...) on success.
# Exit:   0 on success, non-zero on failure — callers proceed without it and
#         the agent simply falls back to interactive auth.
set -u

USER_NAME="${1:-$(whoami)}"
HOME_DIR="${2:-$HOME}"

PLUGIN="/usr/local/emhttp/plugins/unraid-aicliagents"
DAEMON="$PLUGIN/src/secret-service/secret-service-daemon"
SS_BASE="/tmp/unraid-aicliagents/secret-service"
RTDIR="$SS_BASE/$USER_NAME"
SOCK="$RTDIR/bus"
ADDR="unix:path=$SOCK"
STORE="$HOME_DIR/.local/share/aicli-keyring/keyring.json"
BUS_PID="$RTDIR/bus.pid"
DAEMON_PID="$RTDIR/daemon.pid"
LOG="$RTDIR/daemon.log"

[ -x "$DAEMON" ] || { echo "secret-service: daemon binary missing or not executable: $DAEMON" >&2; exit 1; }
command -v dbus-daemon >/dev/null 2>&1 || { echo "secret-service: dbus-daemon not on PATH" >&2; exit 1; }

# Runtime base is world-writable so non-root agent users can create their own
# per-user subdir; the per-user dir itself is private (0700).
mkdir -p "$SS_BASE" 2>/dev/null
chmod 0777 "$SS_BASE" 2>/dev/null
mkdir -p "$RTDIR" 2>/dev/null
chmod 0700 "$RTDIR" 2>/dev/null

_alive() {
    local p
    p=$(cat "$1" 2>/dev/null) || return 1
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null
}

# Fast path — already running for this user.
if [ -S "$SOCK" ] && _alive "$BUS_PID" && _alive "$DAEMON_PID"; then
    echo "$ADDR"
    exit 0
fi

# Serialise bring-up so two concurrent sessions don't race two buses onto the
# same socket path.
exec 9>"$RTDIR/.up.lock"
flock 9

# Re-check under the lock — another session may have just brought it up.
if [ -S "$SOCK" ] && _alive "$BUS_PID" && _alive "$DAEMON_PID"; then
    echo "$ADDR"
    exit 0
fi

# Clear any stale remnants.
_alive "$DAEMON_PID" && kill "$(cat "$DAEMON_PID" 2>/dev/null)" 2>/dev/null
_alive "$BUS_PID" && kill "$(cat "$BUS_PID" 2>/dev/null)" 2>/dev/null
rm -f "$SOCK" "$BUS_PID" "$DAEMON_PID" 2>/dev/null

# Private session bus at a fixed, predictable address.
dbus-daemon --session --address="$ADDR" --nopidfile --fork --print-pid >"$BUS_PID" 2>/dev/null
_i=0
while [ ! -S "$SOCK" ] && [ "$_i" -lt 25 ]; do sleep 0.2; _i=$((_i + 1)); done
[ -S "$SOCK" ] || { echo "secret-service: session bus did not start" >&2; exit 1; }

# Keyring daemon on that bus — detached so it outlives this session and is
# reused by the user's next agent launch. It writes DAEMON_PID once it owns
# org.freedesktop.secrets, which doubles as the readiness signal below.
mkdir -p "$(dirname "$STORE")" 2>/dev/null
DBUS_SESSION_BUS_ADDRESS="$ADDR" setsid "$DAEMON" --store "$STORE" --pidfile "$DAEMON_PID" >>"$LOG" 2>&1 &
disown 2>/dev/null || true

_i=0
while [ "$_i" -lt 25 ]; do
    if _alive "$DAEMON_PID"; then
        echo "$ADDR"
        exit 0
    fi
    sleep 0.2
    _i=$((_i + 1))
done
echo "secret-service: daemon did not claim org.freedesktop.secrets" >&2
exit 1
