#!/bin/bash
# promote.sh — session-safe activation of the version that ci/publish just
# committed (no `plugin install`, so no running session is closed).
# Steps: seed flash with every versioned <FILE> of the .plg (so a reboot
# reproduces the same generation from flash), copy the .plg to flash (the
# /var/log/plugins marker is a symlink to it), stage + validate the payload,
# and activate the generation (atomic src symlink flip). See memory
# "session-safe-publish-promote".
set -euo pipefail
REPO=/mnt/cache/DevelopmentProjects/unraid-extensions/unraid-plg-aicliagents
PLG="$REPO/unraid-aicliagents.plg"
NAME=unraid-aicliagents
FLASH=/boot/config/plugins/$NAME
EMHTTP=/usr/local/emhttp/plugins/$NAME
VERSION="$(grep -o '<!ENTITY version *"[^"]*"' "$PLG" | sed 's/.*"\([^"]*\)"/\1/')"
GITURL="$(grep -o '<!ENTITY gitURL *"[^"]*"' "$PLG" | sed 's/.*"\([^"]*\)"/\1/')"
[ -n "$VERSION" ] && [ -n "$GITURL" ] || { echo "version or gitURL missing"; exit 1; }
echo "version=$VERSION"
# 1. seed flash from the repo, one file per <FILE Name=...><URL>...</URL>
mkdir -p "$FLASH"
names=($(grep -o '<FILE Name="[^"]*"' "$PLG" | sed 's/<FILE Name="//; s/"$//'))
urls=($(grep -o '<URL>[^<]*</URL>' "$PLG" | sed 's#<URL>##; s#</URL>##'))
[ "${#names[@]}" -eq "${#urls[@]}" ] || { echo "FILE/URL count mismatch ${#names[@]} vs ${#urls[@]}"; exit 1; }
for i in "${!names[@]}"; do
  dest="${names[$i]}"; dest="${dest//&name;/$NAME}"; dest="${dest//&version;/$VERSION}"
  src="${urls[$i]}"; src="${src//&name;/$NAME}"; src="${src//&version;/$VERSION}"
  rel="${src#$GITURL/}"
  [ -f "$REPO/$rel" ] || { echo "repo file missing for $dest: $rel"; exit 1; }
  cp -f "$REPO/$rel" "$dest"
  echo "seeded $dest"
done
cp -f "/boot/config/plugins/$NAME.plg" "/tmp/$NAME.plg.before-$VERSION" || true
cp -f "$PLG" "/boot/config/plugins/$NAME.plg"
# 2. stage + validate + activate
# shellcheck source=/dev/null
source "$REPO/src/scripts/installer/generation.sh"
TARBALL="$FLASH/aicli-src-$VERSION.tar.gz"
GEN_ID="$(aicli_payload_generation_id "$TARBALL" "$VERSION")"
echo "generation=$GEN_ID"
STAGE="$EMHTTP/.generations/.stage.$$"
rm -rf "$STAGE"; mkdir -p "$STAGE"
tar -xzf "$TARBALL" -C "$STAGE"
[ -d "$STAGE/src" ] || { echo "tarball has no src/ at top level"; ls "$STAGE"; exit 1; }
# This session-safe path skips finalize.sh (the real installer) entirely, so
# it must repeat its "Setting file permissions" block itself or a promoted
# generation ships with the wrong bits: event hooks that Unraid silently
# never execs, or (found 2026-09-15) a secret-service-daemon binary that
# can't even start. Mirrors src/scripts/installer/finalize.sh exactly —
# keep the two in sync if that block changes.
find "$STAGE" -path "$STAGE/agents" -prune -o -type d -exec chmod 755 {} \;
chmod -R 755 "$STAGE/src/scripts" 2>/dev/null || true
chmod -R 755 "$STAGE/src/includes" 2>/dev/null || true
chmod -R 755 "$STAGE/src/assets" 2>/dev/null || true
chmod -R 755 "$STAGE/src/secret-service" 2>/dev/null || true
chmod -R 755 "$STAGE/bin" 2>/dev/null || true
chmod 755 "$STAGE/agents" 2>/dev/null || true
chmod 755 "$STAGE/src/event"/* 2>/dev/null || true
aicli_validate_staged_payload "$STAGE"
aicli_activate_generation "$EMHTTP" "$STAGE" "$GEN_ID"
# 3. Refresh the root entry points (Forgejo #367). Unraid runs these copies at
# the plugin root, not the ones inside the generation. install-engine.sh
# refreshes them after each activation; without the same step here a promote
# left the root AICliAjax.php and .page files on an older version (for #367,
# on the version that loaded code through the src link twice). Temp file plus
# rename, so a request reads the whole old file or the whole new one.
for f in AICliAjax.php AICliAgentsManager.page AICliAgents.page AICliMenuIcon.page AICliOpenFile.page AICliRelayMcp.page ArrayStopWarning.page README.md; do
  if [ -f "$EMHTTP/src/$f" ]; then
    cp -f "$EMHTTP/src/$f" "$EMHTTP/$f.tmp.$$" && mv -f "$EMHTTP/$f.tmp.$$" "$EMHTTP/$f"
  fi
done
echo "active=$(readlink "$EMHTTP/src")"
grep -o 'version="[^"]*"' "/var/log/plugins/$NAME.plg" | head -1
tmux list-sessions 2>/dev/null | wc -l | sed 's/^/tmux sessions: /'
