#!/bin/bash
# Surgical hot-patch of a RETAINED generation's aicli-shell.sh: replace the
# `exec tmux -u attach-session -d` line with the client-cap block.
#
# ONLY that line is touched. In particular TMUX_TMPDIR is left alone — these
# generations' live sessions are on the shared socket, and repointing them at a
# per-session socket would make a reconnect find no session and start a fresh
# empty agent.
#
# Installed by atomic rename so any process currently executing the old file
# keeps its own inode and is completely unaffected.
set -euo pipefail

BLOCK="$1"; shift
RC=0

for GEN in "$@"; do
    SRC="$GEN/src/scripts/aicli-shell.sh"
    if [ ! -f "$SRC" ]; then echo "SKIP (missing): $SRC"; continue; fi

    if ! grep -q '^exec tmux -u attach-session -d -t "\$SESSION"' "$SRC"; then
        if grep -q 'prune_excess_tmux_clients' "$SRC"; then
            echo "SKIP (already patched): $SRC"
        else
            echo "SKIP (unexpected attach line): $SRC"; RC=1
        fi
        continue
    fi

    TMP="$SRC.hotpatch.$$"
    awk -v blockfile="$BLOCK" '
        $0 ~ /^exec tmux -u attach-session -d -t "\$SESSION"/ {
            while ((getline line < blockfile) > 0) print line
            close(blockfile)
            next
        }
        { print }
    ' "$SRC" > "$TMP"

    # Refuse to install anything that is not valid bash or that lost content.
    bash -n "$TMP" || { echo "FAIL syntax: $SRC"; rm -f "$TMP"; RC=1; continue; }
    before=$(wc -l < "$SRC"); after=$(wc -l < "$TMP")
    if [ "$after" -le "$before" ]; then echo "FAIL shrank: $SRC"; rm -f "$TMP"; RC=1; continue; fi
    grep -q 'prune_excess_tmux_clients "\$SESSION"' "$TMP" || { echo "FAIL no cap call: $SRC"; rm -f "$TMP"; RC=1; continue; }
    grep -q '^exec tmux -u attach-session -t "\$SESSION"' "$TMP" || { echo "FAIL no attach: $SRC"; rm -f "$TMP"; RC=1; continue; }
    grep -q -- '-d -t "\$SESSION"' "$TMP" && { echo "FAIL -d survived: $SRC"; rm -f "$TMP"; RC=1; continue; }
    # The socket location MUST be untouched for a retained generation.
    diff <(grep -n 'TMUX_TMPDIR=' "$SRC") <(grep -n 'TMUX_TMPDIR=' "$TMP") >/dev/null \
        || { echo "FAIL TMUX_TMPDIR changed: $SRC"; rm -f "$TMP"; RC=1; continue; }

    chmod --reference="$SRC" "$TMP" 2>/dev/null || chmod 0755 "$TMP"
    mv -f "$TMP" "$SRC"
    echo "PATCHED $SRC  ($before -> $after lines)"
done
exit $RC
