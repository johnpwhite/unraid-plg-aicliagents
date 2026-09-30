#!/bin/bash
# tools/triage-upgrade.sh <agentId> [--lines=N]
#
# One-call diagnostic bundle for "the upgrade of <agent> failed / is stuck".
# Prints everything an operator (or an AI session) otherwise collects in a
# dozen separate reads, then a verdict. READ-ONLY: it never writes, kills,
# mounts or unmounts anything. Run on the Unraid box that hosts the plugin.
#
# Sections:
#   1. activity tray entry            /tmp/unraid-aicliagents/activity/install_<agent>.json
#   2. install-status marker          /tmp/unraid-aicliagents/install-status-<agent>
#   3. barriers                       upgrade-relaunch-<agent>.json · pending-activation-<agent>.json
#   4. install worker                 live install-bg.php <agent> process?
#   5. layer state                    live overlay lowerdir vs newest baked layer on flash
#   6. holders                        fuser -m on the agent mount, with workspace id / cmd / age
#   7. versions.json entry
#   8. supervisor queue / retry pen   upgrade-agent-<agent> job
#   9. lifecycle.log (UTC)            last N lines mentioning the agent or its activation
#  10. debug.log (local time)         the last install block for the agent
#  11. verdict
#
# Background: docs/specs/UPGRADE_ACTIVATION_WITHOUT_CLOSED_SET.md and
# docs/specs/CURL_INSTALL_VERSION_PIN_AND_TIMEOUT.md (2026-09-06 "upgrade all").
set -u
AGENT="${1:-}"
LINES=40
for a in "${@:2}"; do case "$a" in --lines=*) LINES="${a#*=}" ;; esac; done
case "$AGENT" in ''|*[!a-z0-9-]*) echo "usage: $0 <agentId> [--lines=N]" >&2; exit 2 ;; esac

TMP=/tmp/unraid-aicliagents
FLASH=/boot/config/plugins/unraid-aicliagents
PERSIST="$FLASH/persistence"
MNT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$AGENT"
LIFE="$FLASH/lifecycle.log"
DEBUG="$TMP/debug.log"

hr() { printf '\n== %s ==\n' "$*"; }
json() { # pretty-print a JSON file (php is a plugin dependency; no jq assumption)
  [ -s "$1" ] || { echo "(absent)"; return; }
  php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d===null? file_get_contents($argv[1]) : json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";' "$1" 2>/dev/null || cat "$1"
}
field() { php -r '$d=json_decode(@file_get_contents($argv[1]),true); echo is_array($d)?(string)($d[$argv[2]]??""):"";' "$1" "$2" 2>/dev/null; }

echo "triage-upgrade: agent=$AGENT  host=$(hostname)  now=$(date -Is)  (lifecycle.log is UTC, debug.log is local)"

hr "1. activity tray entry"
ACT="$TMP/activity/install_$AGENT.json"; json "$ACT"
ACT_STATUS="$(field "$ACT" status)"; ACT_ERR="$(field "$ACT" error)"

hr "2. install-status marker"
MARK="$TMP/install-status-$AGENT"; json "$MARK"
[ -f "$MARK" ] && echo "marker age: $(( $(date +%s) - $(stat -c %Y "$MARK") ))s"
MARK_PHASE="$(field "$MARK" phase)"; MARK_PROG="$(field "$MARK" progress)"

hr "3. barriers"
echo "-- closed-set manifest ($TMP/upgrade-relaunch-$AGENT.json)"; json "$TMP/upgrade-relaunch-$AGENT.json"
echo "-- pending activation ($TMP/pending-activation-$AGENT.json)";  json "$TMP/pending-activation-$AGENT.json"
echo "-- queued (safe) upgrade request"; json "$TMP/pending-agent-upgrade-$AGENT.json"
HAS_SET=0; [ -n "$(field "$TMP/upgrade-relaunch-$AGENT.json" closed)" ] && HAS_SET=1
HAS_PEND=0; [ -s "$TMP/pending-activation-$AGENT.json" ] && HAS_PEND=1

hr "4. install worker"
BG="$(pgrep -af "install-bg.php $AGENT( |$)" 2>/dev/null | grep -v pgrep || true)"
if [ -n "$BG" ]; then echo "RUNNING: $BG"; else echo "none"; fi

hr "5. layer state"
LIVE_LOWER="$(grep " $MNT overlay " /proc/mounts | sed -n 's/.*lowerdir=\([^,]*\).*/\1/p' | head -1)"
if [ -z "$LIVE_LOWER" ]; then
  if grep -q " $MNT " /proc/mounts; then echo "mount: $(grep " $MNT " /proc/mounts | awk '{print $3}') (not an overlay stack)"; else echo "mount: NOT MOUNTED"; fi
else
  echo "live lowerdir: $LIVE_LOWER"
fi
NEWEST="$(ls -1t "$PERSIST"/agent_"$AGENT"_*.sqsh 2>/dev/null | head -1)"
echo "newest baked layer: ${NEWEST:-none}"
LAYER_LIVE="n/a"
if [ -n "$NEWEST" ] && [ -n "$LIVE_LOWER" ]; then
  STEM="$(basename "$NEWEST" .sqsh)"
  case ":$LIVE_LOWER:" in *"/$STEM:"*|*"/$STEM"*) LAYER_LIVE=yes ;; *) LAYER_LIVE=NO ;; esac
fi
echo "newest layer live: $LAYER_LIVE"
# Plain-directory agents (2026-09-24): the stable name points at a generation
# directory; the bind at the mount point must show that same directory.
PT_STABLE="$PERSIST/passthrough/agents/$AGENT"
if [ -L "$PT_STABLE" ]; then
  PT_GEN="$(readlink "$PT_STABLE")"
  # #317: on the side-by-side layout agents/<id> is a symlink to the bind at
  # agents/.versions/<id>/<gen>; mountinfo records the real path.
  PT_MNT_REAL="$(readlink -f "$MNT" 2>/dev/null || echo "$MNT")"
  PT_BOUND="$(awk -v m="$PT_MNT_REAL" '$5==m {print $4}' /proc/self/mountinfo | tail -1)"
  echo "plain-dir stable generation: $PT_GEN"
  echo "plain-dir mount shows: ${PT_BOUND:-NOT MOUNTED}"
  if [ -L "$MNT" ]; then echo "plain-dir layout: side by side (agents/$AGENT -> $(readlink "$MNT"))"
  else echo "plain-dir layout: OLD one-mount layout (converts at the first launch with no session of this agent)"; fi
  for PT_G in "$PERSIST/passthrough/agents/.versions/$AGENT"/*/; do
    [ -d "$PT_G" ] || continue
    PT_G="$(basename "$PT_G")"
    PT_B="no bind"; mountpoint -q "$(dirname "$MNT")/.versions/$AGENT/$PT_G" 2>/dev/null && PT_B="bound"
    echo "plain-dir version on flash: $PT_G ($PT_B, $(du -sm "$PERSIST/passthrough/agents/.versions/$AGENT/$PT_G" 2>/dev/null | cut -f1) MB)"
  done
  if [ -n "$PT_BOUND" ]; then
    if [ "$(stat -L -c '%d:%i' "$MNT" 2>/dev/null)" = "$(stat -L -c '%d:%i' "$PT_STABLE" 2>/dev/null)" ]; then
      echo "plain-dir generation live: yes"
    else
      echo "plain-dir generation live: NO (a new workspace runs the previous version until the mount is rebound)"
      LAYER_LIVE=NO
    fi
  fi
fi
losetup -a 2>/dev/null | grep "agent_${AGENT}_" | sed 's/^/loop: /'
UPPER="$PERSIST/_upper/agents/$AGENT"
[ -d "$UPPER" ] && echo "upper dir: $UPPER ($(du -sm "$UPPER" 2>/dev/null | cut -f1) MB on flash)"

hr "6. holders of the agent mount"
HOLDERS=0
if [ -d "$MNT" ]; then
  for pid in $(fuser -m "$MNT" 2>/dev/null | tr -s ' ' '\n' | grep -E '^[0-9]+$'); do
    HOLDERS=$((HOLDERS+1))
    sid="$(tr '\0' '\n' < "/proc/$pid/environ" 2>/dev/null | sed -n 's/^AICLI_SESSION_ID=//p' | head -1)"
    cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | cut -c1-100)"
    age="$(ps -o etime= -p "$pid" 2>/dev/null | tr -d ' ')"
    who="NOT a plugin workspace"; [ -n "$sid" ] && who="workspace $sid"
    printf 'pid %s · %s · %s · up %s\n' "$pid" "$who" "$cmd" "${age:-?}"
  done
fi
[ "$HOLDERS" -eq 0 ] && echo "none (mount idle)"

hr "7. versions.json"
php -r '$v=json_decode(@file_get_contents($argv[1]),true); echo json_encode($v[$argv[2]] ?? "(no entry)", JSON_PRETTY_PRINT), "\n";' "$FLASH/versions.json" "$AGENT" 2>/dev/null
INSTALLED="$(php -r '$v=json_decode(@file_get_contents($argv[1]),true); echo (string)($v[$argv[2]]["installed"] ?? "");' "$FLASH/versions.json" "$AGENT" 2>/dev/null)"

hr "8. supervisor job upgrade-agent-$AGENT"
ls -la "$TMP/supervisor/queue/"*"_agent_${AGENT}_mount.req" "$TMP/supervisor/jobs-retry/upgrade-agent-${AGENT}.retry" 2>/dev/null || echo "not queued / no retry parked"
# #350: the activation retry state (tries, failures in a row, last outcome, halted).
if [ -f "$TMP/supervisor/jobs-retry/upgrade-agent-${AGENT}.activation" ]; then
    echo "-- activation retry state"
    tr '\n' ' ' < "$TMP/supervisor/jobs-retry/upgrade-agent-${AGENT}.activation"; echo
fi
[ -f "$TMP/supervisor/jobs-retry/upgrade-agent-${AGENT}.retry" ] && json "$TMP/supervisor/jobs-retry/upgrade-agent-${AGENT}.retry"

hr "9. lifecycle.log — last $LINES lines for $AGENT (UTC)"
grep -E "\"agent\":\"$AGENT\"|agent/$AGENT\"" "$LIFE" 2>/dev/null | grep -v -E 'reconcile_ok|boot_integrity_entity|halt_cleared' | tail -n "$LINES" | cut -c1-260

hr "10. debug.log — last install block for $AGENT (local time)"
START="$(grep -n "Background Install Job Started for: $AGENT" "$DEBUG" 2>/dev/null | tail -1 | cut -d: -f1)"
if [ -n "$START" ]; then
  END="$(awk -v s="$START" -v a="$AGENT" 'NR>s && ($0 ~ ("Background Install Job (Complete|FAILED|EXCEPTION) for:? " a) || $0 ~ ("Upgrade: agent layer for " a)) {print NR; exit}' "$DEBUG")"
  [ -z "$END" ] && END=$((START+LINES))
  sed -n "${START},${END}p" "$DEBUG" | grep -E "InstallerService|InstallBG|AICliAgents\]|CurlInstallSource|NpmSource\] \[NPM\] (npm ERR|ERR)|CONSOLIDATE|MOUNT|WARN|ERR" | cut -c1-260 | tail -n "$LINES"
else
  echo "no install block found"
fi

hr "11. verdict"
if [ -n "$BG" ]; then
  echo "INSTALL RUNNING — wait; the tray shows live progress."
elif [ "$ACT_STATUS" = "failed" ] && [ "$LAYER_LIVE" = "NO" ] && [ -n "$NEWEST" ]; then
  echo "LIKELY A FALSE FAILURE: the new layer $(basename "$NEWEST") is baked but not live (installed=$INSTALLED)."
  echo "The merged overlay already serves the new files; activation waits for the mount to go idle (holders: $HOLDERS)."
  [ "$HAS_SET" -eq 0 ] && [ "$HAS_PEND" -eq 0 ] && echo "NO barrier record exists — nothing will activate it (pre-2026.09.07 behaviour). Close the workspace(s) and remount, or upgrade again."
  echo "tray error: ${ACT_ERR:-none}"
elif [ "$ACT_STATUS" = "failed" ]; then
  echo "REAL FAILURE: ${ACT_ERR:-see section 10}. installed=$INSTALLED"
elif [ "$ACT_STATUS" = "waiting" ] || [ "$MARK_PHASE" = "awaiting_activation" ]; then
  echo "PARKED: installed=$INSTALLED, layer live=$LAYER_LIVE, holders=$HOLDERS, closed-set=$HAS_SET, pending-record=$HAS_PEND."
  echo "Activation runs when the holders exit (supervisor retries with a 15 s → 600 s backoff; halted=1 above means it stopped after repeated failures and waits for a session close or a new install)."
elif [ "$LAYER_LIVE" = "NO" ]; then
  echo "QUIET DRIFT: newest layer not live and no activity entry. holders=$HOLDERS. A remount when idle picks it up."
else
  echo "HEALTHY: installed=$INSTALLED, newest layer live=$LAYER_LIVE, tray=${ACT_STATUS:-none}, marker=${MARK_PROG:-none}%."
fi
