#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/storage/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# agent-tmp.sh — "Agent temporary folder" at agent launch (docs/specs/AGENT_TEMP_FOLDER.md R3).
#
# Usage: agent-tmp.sh resolve <user>
#   Prints the folder that TMPDIR and CLAUDE_CODE_TMPDIR must name, or prints nothing (the agent
#   then uses /tmp, which is RAM). The folder is <base>/<user> with mode 700. It never fails the
#   launch: any problem logs ONE warning line and prints nothing.
#
# Settings are read from the plugin cfg file: agent_tmp_location (ram | pool | boot) and
# agent_tmp_path (the base folder of "pool"). The base folder of "boot" is <plugin folder>/agent-tmp.
#   ram   prints nothing.
#   pool  needs a path that is under /mnt/<name>, not under /mnt/user or /mnt/user0 (also after
#         symbolic links are resolved), on a mounted pool or disk, and a folder the user can write.
#   boot  needs an internal boot disk (not a USB stick) and a writable folder.
#
# Test overrides (environment): AICLI_AGENT_TMP_CFG (cfg file), AICLI_AGENT_TMP_MNT (the /mnt root),
# AICLI_AGENT_TMP_MOUNTPOINT (a command that exits 0 for a mount point), AICLI_AGENT_TMP_BOOT_CLASS
# (internal | usb | other, replaces the storage probe), AICLI_AGENT_TMP_PLUGIN_DIR, AICLI_AGENT_TMP_LOG.

set -u

CFG="${AICLI_AGENT_TMP_CFG:-${PLUGIN_BASE}/unraid-aicliagents.cfg}"
MNT="${AICLI_AGENT_TMP_MNT:-/mnt}"
PLUGIN_DIR="${AICLI_AGENT_TMP_PLUGIN_DIR:-${PLUGIN_BASE}}"
LOG="${AICLI_AGENT_TMP_LOG:-${MOUNT_ROOT}/debug.log}"

_warn() {
    mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
    printf '[%s] [AgentTmp] WARN %s Agent temporary files use RAM (/tmp) for this launch.\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG" 2>/dev/null || true
}

_cfg() { # <key> — the saved value, or empty
    [ -r "$CFG" ] || return 0
    sed -nE "s/^$1=\"?([^\"]*)\"?[[:space:]]*\$/\1/p" "$CFG" 2>/dev/null | tail -n 1
}

_is_mount() { # <dir>
    if [ -n "${AICLI_AGENT_TMP_MOUNTPOINT:-}" ]; then
        $AICLI_AGENT_TMP_MOUNTPOINT "$1"
    else
        mountpoint -q "$1" 2>/dev/null
    fi
}

_under_user() { # <path> — true for /mnt/user, /mnt/user0 and anything below them
    case "$1" in
        "$MNT"/user|"$MNT"/user/*|"$MNT"/user0|"$MNT"/user0/*) return 0 ;;
    esac
    return 1
}

# _resolve <path> — absolute path with symbolic links resolved; the folder may not exist yet.
_resolve() {
    local p="$1" tail="" base
    case "$p" in /*) ;; *) return 1 ;; esac
    while [ ! -e "$p" ] && [ "$p" != "/" ]; do
        tail="/$(basename "$p")$tail"
        p="$(dirname "$p")"
    done
    base="$(cd "$p" 2>/dev/null && pwd -P)" || return 1
    [ "$base" = "/" ] && base=""
    printf '%s%s' "$base" "$tail"
}

_boot_class() {
    if [ -n "${AICLI_AGENT_TMP_BOOT_CLASS:-}" ]; then printf '%s' "$AICLI_AGENT_TMP_BOOT_CLASS"; return; fi
    local ctl out
    ctl="$(dirname "${BASH_SOURCE[0]}")/storage/storagectl.sh"
    [ -f "$ctl" ] || { printf 'unknown'; return; }
    out="$(timeout 20 bash "$ctl" probe --persist "$PLUGIN_DIR" 2>/dev/null)"
    case "$out" in
        *'"mount_class":"boot_internal"'*|*'"mount_class": "boot_internal"'*) printf 'internal' ;;
        *'"mount_class":"boot_usb"'*|*'"mount_class": "boot_usb"'*) printf 'usb' ;;
        *) printf 'other' ;;
    esac
}

do_resolve() {
    local user="${1:-root}" loc base real top dir
    case "$user" in ''|*[!A-Za-z0-9._-]*) _warn "The user name '$user' is not usable for the agent temporary folder."; return 0 ;; esac
    loc="$(_cfg agent_tmp_location)"
    case "$loc" in
        pool)
            base="$(_cfg agent_tmp_path)"; base="${base%/}"
            if [ -z "$base" ]; then _warn "The agent temporary folder is set to a pool, but no folder is set."; return 0; fi
            case "$base" in *..*) _warn "The agent temporary folder '$base' has a .. part."; return 0 ;; esac
            real="$(_resolve "$base")" || { _warn "The agent temporary folder '$base' is not a full path."; return 0; }
            if _under_user "$base" || _under_user "$real"; then
                _warn "The agent temporary folder '$base' is under $MNT/user or $MNT/user0, which is not accepted."; return 0
            fi
            case "$real" in
                "$MNT"/*) ;;
                *) _warn "The agent temporary folder '$base' is not under $MNT/<pool or disk>."; return 0 ;;
            esac
            top="${real#"$MNT"/}"; top="$MNT/${top%%/*}"
            if ! _is_mount "$top"; then _warn "The agent temporary folder '$base' is not on a mounted pool or disk ($top is not mounted)."; return 0; fi
            ;;
        boot)
            if [ "$(_boot_class)" != "internal" ]; then _warn "The agent temporary folder is set to the boot disk, but the boot device is not an internal disk."; return 0; fi
            real="${PLUGIN_DIR%/}/agent-tmp"
            ;;
        *) return 0 ;;
    esac
    dir="$real/$user"
    if ! mkdir -p -m 700 "$dir" 2>/dev/null || [ ! -d "$dir" ] || [ ! -w "$dir" ]; then
        _warn "The agent temporary folder '$dir' cannot be made or is not writable."; return 0
    fi
    chmod 700 "$dir" 2>/dev/null || true
    printf '%s\n' "$dir"
    return 0
}

case "${1:-}" in
    resolve) do_resolve "${2:-root}" ;;
    *) echo "usage: agent-tmp.sh resolve <user>" >&2; exit 2 ;;
esac
exit 0
