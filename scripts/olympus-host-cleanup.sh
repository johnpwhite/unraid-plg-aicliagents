#!/usr/bin/env bash
# Olympus host helpers for AI CLI Agents rollout.
# Usage (from a machine that can SSH to olympus):
#   ./scripts/olympus-host-cleanup.sh          # remove makeshift Codex / stray CLIs
#   ./scripts/olympus-host-cleanup.sh --smoke  # also smoke host CLI + managed HOME
set -euo pipefail

HOST="${AICLI_OLYMPUS_HOST:-olympus}"
SMOKE=0
for arg in "$@"; do
  case "$arg" in
    --smoke) SMOKE=1 ;;
    -h|--help)
      sed -n '1,8p' "$0"
      exit 0
      ;;
  esac
done

ssh_olympus() {
  ssh -o BatchMode=yes -o ConnectTimeout=15 "$HOST" "$@"
}

echo "==> Checking reachability ($HOST)"
if ! ssh_olympus 'echo ok; hostname; uptime'; then
  echo "ERROR: cannot SSH to $HOST. Bring olympus online (power/network), then re-run." >&2
  exit 1
fi

echo "==> Removing makeshift Codex / stray agent installs (preserving plugin paths)"
ssh_olympus 'bash -s' <<'REMOTE'
set -euo pipefail
PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Do not touch plugin-owned trees.
safe_rm() {
  local p="$1"
  case "$p" in
    "$PLUGIN_ROOT"|"$PLUGIN_ROOT"/*|/boot/config/plugins/unraid-aicliagents|/boot/config/plugins/unraid-aicliagents/*)
      echo "skip plugin path: $p"
      return 0
      ;;
  esac
  if [ -e "$p" ] || [ -L "$p" ]; then
    echo "remove: $p"
    rm -rf "$p"
  fi
}

# Common makeshift locations for a hand-rolled Codex install on Unraid.
safe_rm /root/.npm-global
safe_rm /root/.local/share/codex
safe_rm /usr/local/lib/node_modules/@openai/codex
safe_rm /usr/local/lib/node_modules/@openai/codex-linux-x64

# Orphan host wrappers that are NOT the plugin proxy (plugin proxies are
# symlinks into $PLUGIN_ROOT/bin).
for cmd in codex claude opencode pi agy agent cursor-agent; do
  for candidate in "/usr/local/bin/$cmd" "/root/.local/bin/$cmd" "/root/bin/$cmd"; do
    [ -e "$candidate" ] || [ -L "$candidate" ] || continue
    target=$(readlink -f "$candidate" 2>/dev/null || true)
    case "$target" in
      "$PLUGIN_ROOT"/*) echo "keep plugin proxy: $candidate -> $target" ;;
      *)
        echo "remove orphan wrapper: $candidate (-> ${target:-none})"
        rm -f "$candidate"
        ;;
    esac
  done
done

# npm global packages often used for a makeshift install
if command -v npm >/dev/null 2>&1; then
  npm uninstall -g @openai/codex 2>/dev/null || true
  npm uninstall -g @anthropic-ai/claude-code 2>/dev/null || true
fi

# nvm / fnm node trees with global agent CLIs (best-effort)
for base in /root/.nvm/versions/node /root/.fnm/node-versions; do
  [ -d "$base" ] || continue
  find "$base" -type d -path '*/lib/node_modules/@openai/codex' 2>/dev/null | while read -r d; do
    echo "remove: $d"
    rm -rf "$d"
  done
done

echo "Makeshift cleanup done."
REMOTE

if [ "$SMOKE" -eq 1 ]; then
  echo "==> Smoke: plugin presence + host CLI entry points"
  ssh_olympus 'bash -s' <<'REMOTE'
set -euo pipefail
PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"
test -d "$PLUGIN_ROOT" || { echo "Plugin not installed at $PLUGIN_ROOT"; exit 2; }
test -f /etc/profile.d/aicliagents.sh || echo "WARN: shell integration missing"
source /etc/profile.d/aicliagents.sh 2>/dev/null || true
for cmd in claude opencode codex pi agy; do
  if command -v "$cmd" >/dev/null 2>&1 || [ -x "$PLUGIN_ROOT/bin/$cmd" ]; then
    echo "OK entry: $cmd"
  else
    echo "MISSING entry: $cmd (install agent from Store if needed)"
  fi
done
HOME_OVERLAY="/tmp/unraid-aicliagents/work/$(whoami)/home"
echo "Managed HOME overlay: $HOME_OVERLAY (exists=$( [ -d "$HOME_OVERLAY" ] && echo yes || echo no ))"
echo "Smoke complete. After reboot, re-check the same commands + auth under managed HOME."
REMOTE
fi

echo "==> Done"
