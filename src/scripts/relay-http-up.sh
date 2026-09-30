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
#         relay-http-up.sh audit        (supervisor tick, see below)
# Exit:   0 on success; non-zero if the listener could not be brought up.
#         audit: 0 = nothing wrong (or fixed now); 1 = the listener is
#         turned on, was not running, and could not be started; 4 = the
#         wanted state is unknown, so the caller must reconcile from settings.
#         (#337) A listener that is turned on but not running is started again.
#
# Ownership (#331). The pidfile names only the listener that THIS script
# started last. A listener from an older generation whose pidfile was lost or
# replaced kept 0.0.0.0:8237 for 8 days on .4 while the setting said "off", and
# the next listener failed with "Address already in use". So each decision here
# looks at ALL listener processes of this plugin: each process whose command
# line names a relay-http-server.php under the plugin directory, from ANY
# generation. One may live — the current generation, started with the wanted
# address — and only while the listener is turned on. Every other one is
# stopped, and each stop is logged. A process whose command line does not name
# a Relay listener is never signalled.
set -u

ACTION="${1:-start}"
BIND="${2:-0.0.0.0}"
PORT="${3:-8237}"

PLUGIN_ROOT="${AICLI_RELAY_HTTP_PLUGIN_ROOT:-/usr/local/emhttp/plugins/unraid-aicliagents}"
PLUGIN_ROOT="${PLUGIN_ROOT%/}"
# Pin the generation the same way the Relay MCP command does (#112), so an
# activation cannot swap the server out from under a running listener.
PLUGIN_SRC="${AICLI_PLUGIN_SRC:-$(readlink -f "$PLUGIN_ROOT/src" 2>/dev/null || echo "$PLUGIN_ROOT/src")}"
SERVER="$PLUGIN_SRC/scripts/relay-http-server.php"

# Runtime state lives on /tmp, never /mnt/user: a blocking flock over shfs can
# starve the FUSE worker pool and freeze every share on the host.
RTDIR="${AICLI_RELAY_HTTP_RUNTIME:-/tmp/unraid-aicliagents/relay-http}"
PIDFILE="$RTDIR/listener.pid"
# The wanted state ("on <bind> <port>" or "off"), written by every start and
# stop. The supervisor audit reads it, so the audit needs no PHP.
WANTFILE="$RTDIR/wanted"
LOG="$RTDIR/listener.log"
PROC_ROOT="${AICLI_RELAY_HTTP_PROC_ROOT:-/proc}"
# Last automatic restart by the supervisor audit that did not come up (#337).
RESTART_FAIL="$RTDIR/autostart.failed"
RESTART_BACKOFF="${AICLI_RELAY_HTTP_RESTART_BACKOFF:-300}"

# The shared spawn helper (#337) of this same generation.
# shellcheck source=src/scripts/aicli-detach.sh
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/aicli-detach.sh"

mkdir -p "$RTDIR" 2>/dev/null
chmod 0700 "$RTDIR" 2>/dev/null

_alive() {
    local p
    p=$(cat "$PIDFILE" 2>/dev/null) || return 1
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null
}

_pid_cmdline() {
    local p="$1"
    [ -r "$PROC_ROOT/$p/cmdline" ] || return 1
    tr '\0' '\n' < "$PROC_ROOT/$p/cmdline" 2>/dev/null
}

# True when the command line names a relay-http-server.php under this plugin's
# directory, in any generation (.generations/<id>/src, the old flat src, ...).
_is_plugin_listener() {
    local p="$1" token
    while IFS= read -r token; do
        case "$token" in
            "$PLUGIN_ROOT"/*/relay-http-server.php) return 0 ;;
        esac
    done < <(_pid_cmdline "$p")
    return 1
}

# The pidfile may name a listener started with an AICLI_PLUGIN_SRC override
# (a test, a start by hand). Signal it only while it is still a Relay listener.
_is_relay_process() {
    local p="$1" token
    while IFS= read -r token; do
        case "$token" in
            */relay-http-server.php) return 0 ;;
        esac
    done < <(_pid_cmdline "$p")
    return 1
}

_is_current_generation() {
    local p="$1" token
    while IFS= read -r token; do
        [ "$token" = "$SERVER" ] && return 0
    done < <(_pid_cmdline "$p")
    return 1
}

# Current generation AND started with the wanted address: the server takes
# "<bind> <port>" as the two arguments after its own path.
_is_wanted() {
    local p="$1" want_bind="$2" want_port="$3" token state=0
    while IFS= read -r token; do
        case "$state" in
            0) [ "$token" = "$SERVER" ] && state=1 ;;
            1) [ "$token" = "$want_bind" ] || return 1; state=2 ;;
            2) [ "$token" = "$want_port" ] && return 0; return 1 ;;
        esac
    done < <(_pid_cmdline "$p")
    return 1
}

# Every listener process of this plugin, any generation. Never this shell.
# One grep over all command lines finds the candidates (cheap enough for a
# 30 s supervisor tick); the exact token check then confirms each one.
_plugin_listener_pids() {
    local f p
    while IFS= read -r f; do
        p="${f#"$PROC_ROOT"/}"; p="${p%/cmdline}"
        [ "$p" = "$$" ] && continue
        _is_plugin_listener "$p" && echo "$p"
    done < <(grep -lasF -- "relay-http-server.php" "$PROC_ROOT"/[0-9]*/cmdline 2>/dev/null)
}

_log_stop() {
    local p="$1" why="$2" what msg
    what=$(_pid_cmdline "$p" 2>/dev/null | tr '\n' ' ')
    msg="relay-http: stopped listener pid $p ($why): ${what% }"
    echo "$(date '+%Y-%m-%d %H:%M:%S') $msg" >> "$LOG" 2>/dev/null
    logger -t unraid-aicliagents "$msg" 2>/dev/null || true
}

_stop_relay_pid() {
    local p="$1" why="${2:-stop}"
    # A stale pidfile may have been reused by an unrelated process. Never
    # signal it merely because kill -0 succeeds; identify our listener first.
    _is_relay_process "$p" || return 0
    _log_stop "$p" "$why"
    kill -TERM "$p" 2>/dev/null || true
    for _ in $(seq 1 10); do kill -0 "$p" 2>/dev/null || return 0; sleep 0.2; done
    kill -0 "$p" 2>/dev/null && kill -KILL "$p" 2>/dev/null || true
}

# Stop every plugin listener except $1 (may be empty). Prints how many stopped.
_stop_all_except() {
    local keep="$1" why="$2" p n=0
    for p in $(_plugin_listener_pids); do
        [ "$p" = "$keep" ] && continue
        _stop_relay_pid "$p" "$why"; n=$((n + 1))
    done
    echo "$n"
}

# The one listener to keep for "on <bind> <port>": the pidfile's when it is
# wanted, else any wanted plugin listener (adopted into the pidfile).
_wanted_keeper() {
    local want_bind="$1" want_port="$2" p
    if _alive; then
        p=$(cat "$PIDFILE")
        if _is_wanted "$p" "$want_bind" "$want_port"; then echo "$p"; return 0; fi
    fi
    for p in $(_plugin_listener_pids); do
        if _is_wanted "$p" "$want_bind" "$want_port"; then echo "$p" > "$PIDFILE"; echo "$p"; return 0; fi
    done
    return 1
}

_stop_everything() {
    local why="$1" p
    if _alive; then
        p=$(cat "$PIDFILE")
        _is_plugin_listener "$p" || _stop_relay_pid "$p" "$why"
    fi
    _stop_all_except "" "$why" >/dev/null
    rm -f "$PIDFILE" 2>/dev/null
}

case "$ACTION" in
  status)
    if _alive; then
        p=$(cat "$PIDFILE")
        if _is_current_generation "$p"; then echo "running $p"; exit 0; fi
        echo "stale $p"; exit 1
    fi
    echo "stopped"; exit 1
    ;;
  stop)
    echo "off" > "$WANTFILE" 2>/dev/null
    _stop_everything "listener turned off"
    exit 0
    ;;
  audit)
    # Cheap supervisor check: one /proc scan and no PHP.
    pids=$(_plugin_listener_pids)
    want=$(cat "$WANTFILE" 2>/dev/null) || want=""
    if [ -z "$pids" ]; then
        # #337: no listener runs. When it is turned on, start it again: a
        # listener that crashed, or that was stopped by hand, otherwise stayed
        # down until the next boot or settings save. A start that failed is
        # retried only after RESTART_BACKOFF seconds (a bad certificate or a
        # taken port fails at once and would otherwise be retried every tick).
        case "$want" in
          "on "*)
            read -r _ wbind wport <<<"$want"
            if [ -f "$RESTART_FAIL" ]; then
                last=$(stat -c %Y "$RESTART_FAIL" 2>/dev/null || echo 0)
                [ $(( $(date +%s) - last )) -ge "$RESTART_BACKOFF" ] || exit 0
            fi
            msg="relay-http: listener is turned on but not running — starting it on $wbind:$wport"
            echo "$(date '+%Y-%m-%d %H:%M:%S') $msg" >> "$LOG" 2>/dev/null
            logger -t unraid-aicliagents "$msg" 2>/dev/null || true
            if bash "$0" start "$wbind" "$wport"; then
                rm -f "$RESTART_FAIL" 2>/dev/null
                exit 0
            fi
            touch "$RESTART_FAIL" 2>/dev/null
            exit 1 ;;
          off) exit 0 ;;
        esac
        # Wanted state unknown (first tick after a boot or an update from an
        # older version): the caller reconciles from the stored settings.
        exit 4
    fi
    case "$want" in
      off)
        _stop_everything "listener is turned off"
        exit 0 ;;
      "on "*)
        read -r _ wbind wport <<<"$want"
        keeper=$(_wanted_keeper "$wbind" "$wport") || keeper=""
        n=$(_stop_all_except "$keeper" "not the current listener")
        # Start a successor only when this audit removed the listener that
        # held the port. Never retry a listener that failed by itself.
        if [ -z "$keeper" ] && [ "$n" -gt 0 ]; then
            exec bash "$0" start "$wbind" "$wport"
        fi
        exit 0 ;;
    esac
    exit 4
    ;;
  start) ;;
  *) echo "usage: relay-http-up.sh start|stop|status|audit [bind] [port]" >&2; exit 2 ;;
esac

# Record the wanted state first, so a failed start still tells the audit what
# to retry (with back-off) instead of asking PHP to reconcile on every tick.
echo "on $BIND $PORT" > "$WANTFILE" 2>/dev/null
[ -f "$SERVER" ] || { echo "relay-http: server missing: $SERVER" >&2; exit 1; }
# A start resets the audit's restart back-off; a failed audit restart sets it again.
rm -f "$RESTART_FAIL" 2>/dev/null

# Fast path — the wanted listener already runs on THIS generation. Every other
# listener of this plugin (an older generation, another address, a copy whose
# pidfile was lost) is stopped. No agent workspace is touched or restarted.
keeper=$(_wanted_keeper "$BIND" "$PORT") || keeper=""
_stop_all_except "$keeper" "replaced by the current listener" >/dev/null
[ -n "$keeper" ] && exit 0
if _alive; then _stop_relay_pid "$(cat "$PIDFILE")" "replaced by the current listener"; fi
rm -f "$PIDFILE" 2>/dev/null

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
keeper=$(_wanted_keeper "$BIND" "$PORT") || keeper=""
_stop_all_except "$keeper" "replaced by the current listener" >/dev/null
[ -n "$keeper" ] && exit 0

# Look for "listening on" only in what THIS start writes: an older line in the
# log must not report a listener that has already exited as up.
LOG_OFFSET=$(stat -c %s "$LOG" 2>/dev/null || echo 0)

# #337: start the listener fully detached — new session, stdin /dev/null,
# output to its own log, and NO inherited descriptor. A plain `nohup ... &`
# kept the caller's descriptors: during `plugin install` the listener held the
# installer's output pipe (fd 4) open, so the install never finished. It also
# must not keep the bring-up lock (fd 9), or the next invocation could not
# replace a stale generation.
aicli_detach --log "$LOG" --pidfile "$PIDFILE" -- php "$SERVER" "$BIND" "$PORT" >/dev/null

# Confirm it survived startup rather than reporting a pid that already exited
# (a bad cert or a taken port fails immediately).
for _ in $(seq 1 15); do
    _alive || break
    if tail -c +"$((LOG_OFFSET + 1))" "$LOG" 2>/dev/null | grep -q "listening on"; then exit 0; fi
    sleep 0.2
done

if ! _alive || ! _is_current_generation "$(cat "$PIDFILE" 2>/dev/null)"; then
    rm -f "$PIDFILE" 2>/dev/null
    echo "relay-http: listener exited during startup — see $LOG" >&2
    tail -3 "$LOG" 2>/dev/null >&2
    exit 1
fi
exit 0
