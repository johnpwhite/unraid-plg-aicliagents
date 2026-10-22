#!/bin/bash
# storage_ops.sh -- Phase 5: the storage OPERATIONS library.
#
# Home/agent storage op bodies, extracted from the former standalone trio
# (mount_stack.sh / commit_stack.sh / consolidate_layers.sh) into SUBSHELL
# functions so storagectl.sh can dispatch to them in-process. Each op is
# `op_X() ( ... )`: the `( )` keeps the moved body's own `set -euo pipefail`,
# `exit` codes, EXIT traps and `flock` fds isolated -- so the extraction is
# behaviour-preserving (the 36-case L3.5 suite is the proof). The trio files
# become thin shims that exec `storagectl <verb>`.
#
# The shared libs (common.sh / resolve_paths.sh / boot_integrity.sh /
# atomic_write_layer.sh) are sourced by each op's body exactly as the original
# scripts did -- idempotent re-sourcing inside the subshell, with the original
# $(dirname "$0") rewritten to $_SO_DIR (this file's dir == the storage dir).
#
# GENERATED from the trio by gen_storage_ops.py, then curated. Re-run the L3.5
# suite after any edit to an op body.
#
# @internal Storage-component internal (Epic #1310). op_mount / op_bake /
# op_consolidate / op_release + the manifest-writer php -r snippets (under the
# bake/consolidate locks) are PRIVATE to the storage component — reached only via
# the storagectl seam behind the FileStorage facade. No consumer shells these
# directly (RegressionGuardsTest enforces it).

_SO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
[ -d "$_SO_DIR" ] || _SO_DIR="/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage"

# Use the same 10% size allowance and free-space margin as a normal layer bake.
BACKEND_MIGRATION_BAKE_MARGIN_MB="${AICLI_LAYER_SPACE_MARGIN_MB:-64}"
case "$BACKEND_MIGRATION_BAKE_MARGIN_MB" in
    ''|*[!0-9]*) BACKEND_MIGRATION_BAKE_MARGIN_MB=64 ;;
esac

# lock_holder_describe <lockfile>   (R8, HARD_STOP_RESILIENCE.md)
#
# Print ONE line that names the process which holds <lockfile> open:
#   pid=<pid> cmd=<command line, 120 chars> age=<seconds since it started> [orphan]
# or the word `unknown` when no holder is found. "orphan" means the parent pid is 1:
# the process that started it is gone, but it still holds the lock (for example a
# mksquashfs left over from a killed bake). The lock is NEVER released by force:
# an orphan that still writes a layer holds it for a reason. This helper only READS.
# Order of search: fuser, then /proc/locks (by inode), then a scan of /proc/*/fd.
# Our own pid and our parent are skipped. Always returns 0 and is fast.
lock_holder_describe() {
    local _f="${1:-}" _skip _p _pids="" _inode _fd _cmd _stat _ppid _start _clk _up _age _line
    if [ -z "$_f" ] || [ ! -e "$_f" ]; then echo "unknown"; return 0; fi
    # Pids that are us or our parents (a $( ) subshell has its own pid).
    _skip=" $$ ${BASHPID:-$$} ${PPID:-0} "
    _stat="$(cat "/proc/${BASHPID:-$$}/stat" 2>/dev/null | sed 's/^.*) //')" || _stat=""
    _ppid="$(printf '%s' "$_stat" | awk '{print $2}')" || _ppid=""
    [ -z "$_ppid" ] || _skip="$_skip$_ppid "
    _pick_holder() {
        local _c
        for _c in "$@"; do
            case "$_c" in ''|*[!0-9]*) continue ;; esac
            case "$_skip" in *" $_c "*) continue ;; esac
            [ -d "/proc/$_c" ] || continue
            printf '%s' "$_c"; return 0
        done
        return 1
    }
    _p=""
    # 1. fuser
    if command -v fuser >/dev/null 2>&1; then
        _pids="$(fuser "$_f" 2>/dev/null | grep -oE '[0-9]+' | tr '\n' ' ')" || _pids=""
        # shellcheck disable=SC2086
        _p="$(_pick_holder $_pids)" || _p=""
    fi
    # 2. /proc/locks: the pid column of the line for this inode
    if [ -z "$_p" ] && [ -r /proc/locks ]; then
        _inode="$(stat -c %i "$_f" 2>/dev/null)" || _inode=""
        if [ -n "$_inode" ]; then
            _pids="$(awk -v ino="$_inode" '{n=split($6,a,":"); if (a[n]==ino) print $5}' /proc/locks 2>/dev/null | tr '\n' ' ')" || _pids=""
            # shellcheck disable=SC2086
            _p="$(_pick_holder $_pids)" || _p=""
        fi
    fi
    # 3. scan the open files of every process
    if [ -z "$_p" ]; then
        _pids=""
        for _fd in /proc/[0-9]*/fd/*; do
            if [ "$_fd" -ef "$_f" ] 2>/dev/null; then
                _c="${_fd#/proc/}"; _pids="$_pids ${_c%%/*}"
            fi
        done
        # shellcheck disable=SC2086
        _p="$(_pick_holder $_pids)" || _p=""
    fi
    if [ -z "$_p" ]; then echo "unknown"; return 0; fi
    # Command line: NUL to space, no quote or backslash (the text goes into JSON).
    _cmd="$(tr '\0' ' ' < "/proc/$_p/cmdline" 2>/dev/null | tr -d '"\\\n\r' | cut -c1-120)" || _cmd=""
    [ -n "$_cmd" ] || _cmd="$(tr -d '"\\\n\r' < "/proc/$_p/comm" 2>/dev/null)" || _cmd=""
    _stat="$(sed 's/^.*) //' "/proc/$_p/stat" 2>/dev/null)" || _stat=""
    _ppid="$(printf '%s' "$_stat" | awk '{print $2}')" || _ppid=""
    _start="$(printf '%s' "$_stat" | awk '{print $20}')" || _start=""
    _clk="$(getconf CLK_TCK 2>/dev/null)" || _clk=""
    case "$_clk" in ''|*[!0-9]*|0) _clk=100 ;; esac
    _age="?"
    read -r _up _ < /proc/uptime 2>/dev/null || _up=""
    _up="${_up%%.*}"
    case "$_start:$_up" in
        *[!0-9:]*|:*|*:) ;;
        *) _age=$(( _up - _start / _clk )); [ "$_age" -ge 0 ] || _age=0 ;;
    esac
    _line="pid=$_p cmd=${_cmd:-?} age=$_age"
    [ "$_ppid" != "1" ] || _line="$_line orphan"
    printf '%s\n' "$_line"
    return 0
}

# _sweep_agent_generations <agent_id> <persist_path>
#
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15). Release superseded
# generations of one agent, and ALWAYS return success.
#
# The "always" is load-bearing. op_mount runs under `set -euo pipefail`, and the
# sweep is house-keeping: whether a superseded layer could be released this time
# says nothing about whether the mount this call was asked for is good. An
# earlier form ended in `[ -n "$line" ] && log "$line"`, whose status becomes the
# loop's status and therefore op_mount's — so a sweep with nothing to say made a
# perfectly good mount report failure. Every caller of op_mount reads that exit
# code as "is this agent usable".
_sweep_agent_generations() {
    local id="$1" persist="$2" out=""
    declare -f aicli_gc_agent_generations >/dev/null 2>&1 || return 0
    out="$(aicli_gc_agent_generations "$id" "$persist" 0 2>&1 || true)"
    if [ -n "$out" ]; then
        log "$out"
    fi
    return 0
}

# ---- Forgejo #296 (2026-09-23): generation-switch safety helpers ------------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-23 — consolidate never
# switches generation". These helpers are PURE: they read files and compare
# strings, and they never mount or delete. tests/unit/
# consolidate_generation_switch_test.sh drives them directly.
#
# The failure they prevent: an install wrote the agent into the LIVE
# generation's writable layer. The consolidate then refreshed the mount, the
# refresh bound a NEWER generation with an EMPTY writable layer, and the bake
# read that empty view. The result was a 4096-byte layer, and the reclaim then
# deleted the writable layer that held the real install.

# _dir_has_entries <dir> — true when <dir> exists and holds at least one entry.
_dir_has_entries() {
    [ -n "${1:-}" ] && [ -d "$1" ] && [ -n "$(find "$1" -mindepth 1 -print -quit 2>/dev/null)" ]
}

# _gen_switch_blocked <refresh_only> <live_gen> <target_gen> <live_upper>
# True (0) when a refresh-only op_mount must NOT move the agent to another
# generation. A switch binds the target with a different writable layer, so
# data in the live layer that is not baked yet drops out of the view. An empty
# or absent live layer holds nothing to strand, so the switch is allowed. An
# unknown live layer (empty string) blocks the switch: fail safe.
_gen_switch_blocked() {
    local refresh_only="${1:-0}" live_gen="${2:-}" target_gen="${3:-}" live_upper="${4-}"
    [ "$refresh_only" = "1" ] || return 1
    [ -n "$live_gen" ] && [ -n "$target_gen" ] || return 1
    [ "$live_gen" != "$target_gen" ] || return 1
    [ -n "$live_upper" ] || return 0
    _dir_has_entries "$live_upper"
}

# _upper_rebound_loses_data <baseline_upper> <current_upper>
# True (0) when the writable layer behind the entity changed AND the baseline
# layer still holds data. That data is then not in the view that a bake reads.
# A baseline layer that is gone or empty was adopted or held nothing, so the
# change loses nothing.
_upper_rebound_loses_data() {
    local base="${1:-}" now="${2:-}"
    [ "$base" = "$now" ] && return 1
    _dir_has_entries "$base"
}

# _agent_binary_rel <agent_id> <binary_path>
# Print the registry binary path relative to the agent mount. The registry
# names it as <agent base>/<id>/<rel>. Print nothing when the path does not
# name this agent.
_agent_binary_rel() {
    local id="${1:-}" bin="${2:-}"
    [ -n "$id" ] && [ -n "$bin" ] || return 0
    case "$bin" in
        */"$id"/*) printf '%s\n' "${bin#*/"$id"/}" ;;
        /*) : ;;
        *) printf '%s\n' "$bin" ;;
    esac
}

# _view_lost_upper_content <upper> <view> [binary_seen_before] [binary_rel]
# True (0) when the bake source <view> no longer shows content that the live
# writable layer <upper> holds, or no longer shows the agent binary that the
# view showed before the refresh. The check is cheap: it reads only the top
# level of <upper> and one binary path. A whiteout (a character device in the
# upper) records a deletion, so it is not content.
_view_lost_upper_content() {
    local upper="${1:-}" view="${2:-}" seen="${3:-0}" bin_rel="${4:-}" e name
    [ -n "$view" ] || return 1
    if [ "$seen" = "1" ] && [ -n "$bin_rel" ]; then
        [ -e "$view/$bin_rel" ] || [ -L "$view/$bin_rel" ] || return 0
    fi
    [ -d "$upper" ] || return 1
    for e in "$upper"/* "$upper"/.[!.]* "$upper"/..?*; do
        [ -e "$e" ] || [ -L "$e" ] || continue
        [ -c "$e" ] && continue
        name="${e##*/}"
        [ -e "$view/$name" ] || [ -L "$view/$name" ] || return 0
    done
    return 1
}

# _layer_near_empty <layer_bytes> <upper> [layer_file]
# True (0) when a baked layer holds NO files while the writable layer holds at
# least one non-empty file. That layer cannot contain the data, so the caller
# must not let it replace the older layers or the writable layer.
# A layer of 4096 bytes (override AICLI_MIN_LAYER_BYTES) or less is only a
# CANDIDATE: a small home also packs into one 4096-byte block (Forgejo #306
# proof run, 2026-09-23). With [layer_file] and unsquashfs, the listing
# decides: a layer that lists nothing, or only symlinks, is empty. Without them
# the size alone decides, as before (fail safe: refuse and keep the data).
_layer_near_empty() {
    local bytes="${1:-0}" upper="${2:-}" layer="${3:-}" min="${AICLI_MIN_LAYER_BYTES:-4096}"
    case "$bytes" in ''|*[!0-9]*) bytes=0 ;; esac
    [ "$bytes" -le "$min" ] || return 1
    [ -d "$upper" ] || return 1
    [ -n "$(find "$upper" -type f -size +0 -print -quit 2>/dev/null)" ] || return 1
    if [ -n "$layer" ] && [ -f "$layer" ] && command -v unsquashfs >/dev/null 2>&1; then
        local listing
        listing="$(unsquashfs -lls "$layer" 2>/dev/null)" || return 0   # unreadable: refuse
        # 2026-09-29 (forum report): a layer whose only entries are symlinks is
        # empty too. mksquashfs given a SYMLINKED source packs the link alone
        # (4096 bytes, one entry "squashfs-root/<name> -> <target>"), and the
        # older "any squashfs-root/ line" test let that layer through.
        printf '%s\n' "$listing" \
            | awk 'index($0, " squashfs-root/") && substr($1, 1, 1) != "l" { found = 1 } END { exit found ? 0 : 1 }' \
            && return 1
    fi
    return 0
}

# _consol_view_dir <type> <id> <mount_point>
# 2026-09-29 (docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-29 — a
# consolidate reads the real directory, never the stable name"). Print the REAL
# directory of the merged view that a consolidate reads. On the versioned
# layout, agents/<id> is a SYMLINK to agents/.versions/<id>/<generation>.
# mksquashfs, find and du do not follow a symlink that is given as the source:
# mksquashfs packed only the link (a 4096-byte layer), and the consolidate then
# deleted the real layers. An agent resolves through agent_mount_real (the same
# function the busy-arbiter uses); any other path resolves through readlink -f.
# A path that does not resolve prints unchanged.
_consol_view_dir() {
    local type="${1:-}" id="${2:-}" mnt="${3:-}" real=""
    if [ "$type" = "agent" ] && [ -n "$id" ] && declare -f agent_mount_real >/dev/null 2>&1; then
        real="$(agent_mount_real "$id" 2>/dev/null || true)"
    fi
    if [ -z "$real" ] || [ -L "$real" ]; then
        real="$(readlink -f -- "${real:-$mnt}" 2>/dev/null || true)"
    fi
    printf '%s\n' "${real:-$mnt}"
}

# _consol_mount_busy <type> <id> <mount_point>
# R-026 (#604): the busy gate of a consolidate. It asks home_mount_in_use about
# the REAL mount directory (_consol_view_dir). The stable name of a versioned
# agent is a symlink; /proc/mounts never lists a symlink path, so the gate
# answered "idle" for an agent in use and npm prune wrote into a live view.
_consol_mount_busy() {
    home_mount_in_use "$(_consol_view_dir "${1:-}" "${2:-}" "${3:-}")"
}

# _bake_source_for <type> <upper_dir> <upper_override> <staging_view> <view_mounted 0|1> <sqlite_count>
# #338: PURE. Print "<source dir><TAB><kind>" for one bake. A staged agent
# install whose merged staging view is mounted and holds no SQLite database
# is baked from that VIEW as a fresh base ("consolidated"); everything else is
# baked from its writable layer as a "delta", exactly as before. The SQLite
# case stays on the delta path because that path owns the backup merge.
_bake_source_for() {
    local type="${1:-}" upper="${2:-}" override="${3:-}" view="${4:-}" mounted="${5:-0}" sqlite="${6:-0}"
    case "$sqlite" in ''|*[!0-9]*) sqlite=1 ;; esac
    if [ "$type" = "agent" ] && [ -n "$override" ] && [ -n "$view" ] \
       && [ "$mounted" = "1" ] && [ "$sqlite" -eq 0 ]; then
        printf '%s\t%s\n' "$view" "consolidated"
    else
        printf '%s\t%s\n' "$upper" "delta"
    fi
}

# ---- #372: move a home off the upper of an old mode ---------------------------
# docs/specs/HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins".
# _home_upper_converge <id> <persist> <live_upper>
# Called by op_bake after a bake of a home whose live upper is not the policy
# upper. Remounts the home (op_mount then decides "switch": it copies the
# directory skeleton and binds the policy upper) ONLY when the live upper holds
# no data and no session uses the home. Always returns 0; the result is logged.
# #565: the reason it did NOT move is written to a marker file so the UI can
# show it (UpperPlacementService reads it). A new call clears the old marker.
_converge_reason_file() {
    local sid="${1//[^a-zA-Z0-9_-]/_}"
    printf '%s/.upper_converge_reason_home_%s' "${AICLI_CONVERGE_REASON_DIR:-/tmp/unraid-aicliagents}" "$sid"
}
_home_upper_converge() {
    local id="${1:-}" persist="${2:-}" live="${3:-}" mnt pol rc rf
    [ -n "$id" ] && [ -n "$persist" ] && [ -n "$live" ] || return 0
    rf="$(_converge_reason_file "$id")"
    rm -f "$rf" 2>/dev/null || true
    mnt="/tmp/unraid-aicliagents/work/$id/home"
    pol="$( _entity_paths home "$id" "$persist"; printf '%s' "$UPPER_DIR" )"
    [ -n "$pol" ] && [ "$pol" != "$live" ] || return 0
    if _upper_holds_data "$live"; then
        echo "[$(get_ts)] [INFO] [COMMIT] home $id stays on $live until it holds no data that is not baked (policy upper: $pol)" >> "$DEBUG_LOG"
        lifecycle_log "info" "commit_stack" "home_upper_mode_switch_waiting" \
            "{\"type\":\"home\",\"id\":\"$id\",\"live_upper\":\"$live\",\"policy_upper\":\"$pol\",\"reason\":\"live_upper_holds_data\"}" 2>/dev/null || true
        printf 'live_upper_holds_data\n' > "$rf" 2>/dev/null || true
        return 0
    fi
    if home_mount_in_use "$mnt"; then
        lifecycle_log "info" "commit_stack" "home_upper_mode_switch_waiting" \
            "{\"type\":\"home\",\"id\":\"$id\",\"live_upper\":\"$live\",\"policy_upper\":\"$pol\",\"reason\":\"home_in_use\"}" 2>/dev/null || true
        printf 'home_in_use\n' > "$rf" 2>/dev/null || true
        return 0
    fi
    if op_mount home "$id" "$persist"; then rc=0; else rc=$?; fi
    echo "[$(get_ts)] [INFO] [COMMIT] home $id: idle remount to move off $live finished (rc=$rc)" >> "$DEBUG_LOG"
    return 0
}

# ---- op_mount  (from mount_stack.sh) ----------------------------
op_mount() (
set -euo pipefail
# AICliAgents: OverlayFS Stack Assembly
# Usage: mount_stack.sh <type: agent|home> <id> <persistence_path> [owner] [mount-lock-held] [refresh-only]
#
# Bug #1054: optional 4th arg `owner` — for non-root home overlays, chown the
# UPPER_DIR / WORK_DIR / MNT_POINT to this user so OverlayFS writes inherit
# the user's ownership and the agent can actually write to its $HOME. Empty
# OWNER (the default, used by agent mounts) preserves root-write semantics.
#
# Forgejo #296 (2026-09-23): optional 6th arg `refresh-only` (1 = on). The
# caller wants the CURRENT generation refreshed, never a different one. When a
# newer layer waits and the live generation's writable layer holds data, the
# call defers (exit 2) and does not switch. op_consolidate uses this mode.

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
OWNER="${4:-}"
_MOUNT_LOCK_HELD="${5:-0}"
_REFRESH_ONLY="${6:-0}"

# Bug #1054 v.06: for TYPE=home, default OWNER to $ID. Home overlays use the
# username as the ID, so this auto-applies the OWNER chown for every caller
# (commit_stack.sh remount-after-bake, consolidate_layers.sh remount, future
# callers) without each one having to thread the user arg through. Agent
# mounts (TYPE=agent, ID=claude-code etc.) aren't users; the `id "$OWNER"`
# check below filters them out without further handling.
if [ -z "$OWNER" ] && [ "$TYPE" = "home" ]; then
    OWNER="$ID"
fi

PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$_SO_DIR/common.sh"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

# Source Phase 4a boot integrity classifier (warn mode -- observation only, no halt)
source "$_SO_DIR/boot_integrity.sh" 2>/dev/null || true

# Source generation-id helpers (SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2, 2026-09-09) —
# aicli_agent_generation_id, reused from the plugin's own generation activation.
# Optional dependency, same shape as the sources above: op_mount degrades to
# Phase-1 behaviour (always the stable, unversioned mount point) if this ever
# fails to source, rather than half-apply the versioned layout.
source "$_SO_DIR/../installer/generation.sh" 2>/dev/null || true

log() {
    local msg="[$(get_ts)] [INFO] [MOUNT] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [MOUNT] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ -z "$TYPE" ] || [ -z "$ID" ] || [ -z "$PERSIST_PATH" ]; then
    echo "Usage: $0 <agent|home> <id> <persistence_path>"
    exit 1
fi

# Validate persistence path
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# #505 (CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R18): never build a home's layered
# view while a conversion owns the home. Some of its layers can be on the work
# disk at that moment, so the view would be incomplete, and a workspace that
# starts on it writes into a writable layer the conversion is moving. Every mount
# (page load, workspace start, supervisor) comes through here. Deferred, not
# failed: the caller retries, and the mount works once the conversion ends.
# BOOT_DISK_SPACE.md R3: the same holds for an agent that is being converted.
if { [ "$TYPE" = "home" ] || [ "$TYPE" = "agent" ]; } && [ "${AICLI_CONVERT_SELF:-0}" != "1" ]; then
    if _CONV_WHY="$(convert_mount_blocked "$PERSIST_PATH" "$TYPE" "$ID")"; then
        error "$TYPE $ID is being converted to plain folders ($_CONV_WHY) — mount refused until it ends."
        _op_defer "$TYPE" "$ID" "mount_stack" "mount_stack_convert_running" "convert_running" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\"}"
    fi
fi

# Issue #10: never mount from the array or a pool while it is stopping. Every
# mount converges here: storagectl mount, the post-bake and post-consolidate
# refresh, and the agent and home mounts behind a workspace start. A mount at
# this point holds the pool, so emhttpd's umount fails with "target is busy".
# The defer (exit 2) is the same one the post-bake and post-consolidate refresh
# already handle: the layer is committed, the upper is kept, reclaim waits.
# The shutdown bake in event/stopping never mounts, so it is not affected.
if declare -f aicli_blocked_by_array_stop >/dev/null 2>&1 \
   && aicli_blocked_by_array_stop "$PERSIST_PATH"; then
    error "The array is stopping and $PERSIST_PATH is on it: mount deferred."
    _op_defer "$TYPE" "$ID" "mount_stack" "mount_stack_array_stopping" "array_stopping" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}"
fi

# S-02 (#1352): late-mount defer. A persist path under /mnt/ whose backing mount
# is ABSENT (findmnt resolves the path to the rootfs "/" — the dir exists but the
# pool / Unassigned Device behind it is not mounted yet; UD mounts can land up to
# ~2 min after `started`) is a TRANSIENT condition, not a hard failure: defer
# (exit 2, reason=target_not_mounted) so the caller retries, instead of the old
# hard exit-1 from the durable-fstype check below. Scoped to /mnt/* only so
# /boot and the /tmp itest persist roots are untouched.
case "$PERSIST_PATH" in
    /mnt/*)
        _PERSIST_MNT_TGT="$(findmnt --noheadings --output TARGET --target "$PERSIST_PATH" 2>/dev/null || echo '')"
        if [ "$_PERSIST_MNT_TGT" = "/" ]; then
            error "Persistence path $PERSIST_PATH has no backing mount yet (resolves to rootfs) — deferring; is the device/pool mounted?"
            _op_defer "$TYPE" "$ID" "mount_stack" "mount_stack_target_not_mounted" "target_not_mounted" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}"
        fi
        ;;
esac

_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — mount refused"; exit 1; }

# 1. Detect persistence fstype and derive upper-layer location (#342: auto-detect).
# vfat (USB flash) → ZRAM upper (buffers writes, prevents flash wear).
# Any other durable fstype (ext4/xfs/btrfs/…) → direct disk upper (no RAM cost).
_entity_paths "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE

# #372 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins"): the
# policy mode above is right for a NEW home mount only. A home that is mounted
# on the upper of the other mode keeps that upper while it holds data, and an
# unmounted home whose other-mode upper holds data adopts it. Never bind an
# empty upper over one that holds data: that hides every change in it.
_HOME_UPPER_CHOICE="policy"
_HOME_LIVE_UPPER=""; _HOME_LIVE_WORK=""
if [ "$TYPE" = "home" ]; then
    _HOME_POLICY_UPPER="$UPPER_DIR"; _HOME_POLICY_WORK="$WORK_DIR"; _HOME_POLICY_MODE="$ENTITY_UPPER_MODE"
    _HOME_OTHER_MODE="$(_other_upper_mode "$_HOME_POLICY_MODE")"
    _HOME_OTHER_UPPER="$(_entity_upper_for_mode home "$ID" "$PERSIST_PATH" "$_HOME_OTHER_MODE" upper)"
    _HOME_OTHER_WORK="$(_entity_upper_for_mode home "$ID" "$PERSIST_PATH" "$_HOME_OTHER_MODE" work)"
    _HOME_MOUNTED=0
    if _entity_live_upper "/tmp/unraid-aicliagents/work/$ID/home"; then
        _HOME_MOUNTED=1; _HOME_LIVE_UPPER="$LIVE_UPPER_DIR"; _HOME_LIVE_WORK="$LIVE_WORK_DIR"
    fi
    _HOME_UPPER_CHOICE="$(_home_upper_decide "$_HOME_MOUNTED" "$_HOME_LIVE_UPPER" "$_HOME_POLICY_UPPER" "$_HOME_OTHER_UPPER")"
    case "$_HOME_UPPER_CHOICE" in
        keep_live)
            UPPER_DIR="$_HOME_LIVE_UPPER"
            WORK_DIR="${_HOME_LIVE_WORK:-$WORK_DIR}"
            ENTITY_UPPER_MODE="$(_upper_mode_of "$UPPER_DIR")"
            log "Home $ID is mounted on $UPPER_DIR ($ENTITY_UPPER_MODE), but the policy now gives $_HOME_POLICY_MODE ($_HOME_POLICY_UPPER). The live upper holds data that is not baked — keeping it. A bake saves it; a later idle remount moves the home."
            lifecycle_log "warn" "mount_stack" "home_upper_mode_kept_live" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"live_upper\":\"$UPPER_DIR\",\"policy_upper\":\"$_HOME_POLICY_UPPER\"}" 2>/dev/null || true
            ;;
        adopt)
            UPPER_DIR="$_HOME_OTHER_UPPER"
            WORK_DIR="$_HOME_OTHER_WORK"
            ENTITY_UPPER_MODE="$_HOME_OTHER_MODE"
            log "Home $ID: the $_HOME_POLICY_MODE upper $_HOME_POLICY_UPPER is empty, but the $_HOME_OTHER_MODE upper $UPPER_DIR holds data that is not baked — mounting on it (adopted). A bake saves it; a later idle remount moves the home."
            lifecycle_log "warn" "mount_stack" "home_upper_mode_adopted" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"upper\":\"$UPPER_DIR\",\"policy_upper\":\"$_HOME_POLICY_UPPER\"}" 2>/dev/null || true
            ;;
        conflict)
            error "Home $ID: BOTH the $_HOME_POLICY_MODE upper $_HOME_POLICY_UPPER and the $_HOME_OTHER_MODE upper $_HOME_OTHER_UPPER hold data. One mount cannot show both — refusing to mount so that neither is hidden. Recovery: docs/specs/HOME_STORAGE_LIFECYCLE.md \"2026-09-30 — the live mount wins\"."
            lifecycle_log "error" "mount_stack" "home_upper_mode_conflict" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"policy_upper\":\"$_HOME_POLICY_UPPER\",\"other_upper\":\"$_HOME_OTHER_UPPER\"}" 2>/dev/null || true
            exit 1
            ;;
        policy_hidden_other)
            _HOME_UPPER_CHOICE="policy"
            error "Home $ID is mounted on the policy upper $UPPER_DIR, but the $_HOME_OTHER_MODE upper $_HOME_OTHER_UPPER also holds data that this mount does not show. It is not deleted. Recovery: docs/specs/HOME_STORAGE_LIFECYCLE.md \"2026-09-30 — the live mount wins\"."
            lifecycle_log "error" "mount_stack" "home_upper_mode_hidden_data" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"upper\":\"$UPPER_DIR\",\"hidden_upper\":\"$_HOME_OTHER_UPPER\"}" 2>/dev/null || true
            ;;
        switch)
            log "Home $ID is mounted on $_HOME_LIVE_UPPER, which holds no data (directories only). The remount moves it to the $_HOME_POLICY_MODE upper $_HOME_POLICY_UPPER."
            ;;
    esac
fi

if [ "$ENTITY_UPPER_MODE" = "zram" ]; then
    bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }
fi

# 2. Define mount point
if declare -f agent_mount >/dev/null 2>&1; then
    # SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): route through
    # resolve_paths.sh's agent_mount() (sourced above), falling back to the
    # literal only if sourcing ever failed.
    MNT_POINT="$(agent_mount "$ID")"
else
    MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
fi
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

# Remove a stray/emergency symlink at the mount point (emergency mode can leave
# one there). SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): a symlink
# into THIS agent's OWN agent_versions_dir is a LEGITIMATE, already-activated
# generation, not a stale emergency leftover — stripping it here, before the
# busy-arbiter further below gets a chance to resolve it via agent_mount_real,
# would destroy the only record of what is currently live and mounted, making
# every teardown/reap decision downstream blind (it would see "never mounted"
# and skip tearing the real thing down, risking a second live overlay). Keep
# that one shape; still strip anything else, exactly as before Phase 2.
if [ "$TYPE" = "agent" ] && [ -L "$MNT_POINT" ] && declare -f agent_versions_dir >/dev/null 2>&1; then
    case "$(readlink "$MNT_POINT" 2>/dev/null)" in
        .versions/"$ID"/*) : ;;   # Phase 2 generation symlink -- keep
        *) rm -f "$MNT_POINT" ;;
    esac
elif [ -L "$MNT_POINT" ]; then
    rm -f "$MNT_POINT"
fi
mkdir -p "$UPPER_DIR" "$WORK_DIR" "$MNT_POINT"

# Bug #1054: for non-root home overlays, chown the upperdir + workdir + mount
# point (and its parent) to the agent user. OverlayFS inherits write semantics
# from the upperdir owner — a root-owned upper means the agent gets EACCES on
# every write into the merged view even though `mountpoint -q` reports healthy.
# Empty OWNER (agent overlays) skips this block and preserves root-write.
if [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && id "$OWNER" >/dev/null 2>&1; then
    chown -R "$OWNER" "$UPPER_DIR" "$WORK_DIR" 2>/dev/null || true
    chown "$OWNER" "$MNT_POINT" 2>/dev/null || true
    # Parent of MNT_POINT (e.g. /tmp/unraid-aicliagents/work/<user>/) holds
    # per-session run scripts written by aicli-shell.sh — chown so the agent
    # can mkdir/write alongside its home mount.
    PARENT_DIR="$(dirname "$MNT_POINT")"
    [ -d "$PARENT_DIR" ] && chown "$OWNER" "$PARENT_DIR" 2>/dev/null || true
fi

# 3. Discover Lower Layers (SquashFS volumes), newest-first.
# Ordering is by the per-entity monotonic seq (PRIMARY) then dt (tiebreak), via the
# single shared discovery helper in common.sh — so naming + sort never diverge
# across the writer, mount_stack and storagectl. The helper handles BOTH the
# seq-bearing canonical names and all legacy formats (legacy = seq 0, sorts below).
# See common.sh `_layer_discover_sorted` / `_layer_sort_key`.
# #338 (SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent layer
# retention"): an agent stack ends at its newest base (`_consolidated_` layer
# = the complete agent tree); layers below it are not in the view, so the
# retention sweep can delete them. Homes still stack every layer.
FILES=()
mapfile -t FILES < <(_layer_stack_sorted "$PERSIST_PATH" "$TYPE" "$ID")

LOWERS=""
# WP #1084: track loop mounts created during THIS invocation so the overlay-
# mount failure path below can unmount them. Pre-existing loop mounts (skipped
# at line `if ! mountpoint -q`) are NOT in this list — they may belong to a
# concurrent / prior mount_stack run.
_NEW_LOOP_MOUNTS=()
# #566: what to do when one layer will not mount (layer_mount_guard.sh).
source "$_SO_DIR/layer_mount_guard.sh" 2>/dev/null || true
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true
_LAYER_IDX=0
for sqsh in "${FILES[@]}"; do
    # Mount each squashfs to a temporary loop mount if not already done
    SQSH_NAME=$(basename "$sqsh" .sqsh)
    SQSH_MNT="/tmp/unraid-aicliagents/mnt/$SQSH_NAME"
    _LAYER_IDX=$((_LAYER_IDX + 1))
    mkdir -p "$SQSH_MNT"
    if ! mountpoint -q "$SQSH_MNT"; then
        # #566: keep the error text. A bare "Failed to mount" named no reason.
        if ! _LAYER_MOUNT_ERR="$(mount -o loop,ro "$sqsh" "$SQSH_MNT" 2>&1)"; then
            if declare -f _layer_mount_failed >/dev/null 2>&1; then
                # R-023 (#601): capture the status in the `if`. A bare assignment
                # whose $( ) returns 1 ends the op under set -e, before the loop
                # mounts are released and before the error is logged.
                if _LAYER_MSG="$(_layer_mount_failed "$TYPE" "$ID" "$PERSIST_PATH" "$sqsh" "$_LAYER_MOUNT_ERR" "$LOWERS" "$(( ${#FILES[@]} - _LAYER_IDX ))")"; then
                    _LAYER_RC=0
                else
                    _LAYER_RC=$?
                fi
            else
                _LAYER_MSG="Failed to mount $sqsh: $_LAYER_MOUNT_ERR"
                _LAYER_RC=1
            fi
            if [ "$_LAYER_RC" -eq 0 ]; then
                # The damaged newest layer was set aside; mount the rest.
                log "$_LAYER_MSG"
                rmdir "$SQSH_MNT" 2>/dev/null || true
                continue
            fi
            error "$_LAYER_MSG"
            # WP #1084: clean up any loop mounts we created earlier in this
            # loop so they don't leak when the layer mount fails mid-stack.
            for _lm in "${_NEW_LOOP_MOUNTS[@]}"; do
                umount_or_keep "$_lm" "new layer mount" 2 || true   # #512: never lazy
            done
            exit 1
        fi
        _NEW_LOOP_MOUNTS+=("$SQSH_MNT")
    fi
    [ -n "$LOWERS" ] && LOWERS="$LOWERS:"
    LOWERS="$LOWERS$SQSH_MNT"
done

# 4. Mount OverlayFS
if [ -z "$LOWERS" ]; then
    # Fresh entity or migration failed.
    # Follow-on 1b: the standalone LEGACY_FOUND probe (D-298 *.img / D-342 raw
    # folders) that used to live here is FOLDED INTO boot_integrity_classify — the
    # single owner of "is it safe to mount an empty stack here?". The classifier now
    # returns legacy_unmanaged for the legacy-data case below (strict-halt arm), with
    # a halt marker + recovery card — strictly better than the bare exit 1 here.

    # Phase 4a/4b: classify the empty-glob case before proceeding.
    # F7 (WP#1329): the default is now fail-closed 'unknown', NOT 'genuine_fresh' —
    # if the classifier is undefined (boot_integrity.sh failed to source) or errors,
    # we have NOT certified that this empty persist dir is genuinely fresh, so we must
    # not mount an empty stack over data we couldn't inspect. genuine_fresh is reached
    # ONLY when the classifier explicitly says so.
    _INTEGRITY_STATE="unknown"
    if type boot_integrity_classify >/dev/null 2>&1; then
        # Pass the exact persist dir op_mount is operating on (Follow-on 1b) so the
        # classifier's legacy-data + active-layer verdict matches this mount.
        _INTEGRITY_STATE="$(boot_integrity_classify "$TYPE" "$ID" "$PERSIST_PATH" 2>/dev/null || echo 'unknown')"
    fi

    # Read strict mode from config (default 1). AICLI_ITEST_STRICT overrides it for the
    # L3.5 harness (parallel-safe — no shared-cfg mutation), mirroring AICLI_ITEST_BACKEND.
    _BOOT_INTEGRITY_STRICT="${AICLI_ITEST_STRICT:-$(_rp_read_cfg "boot_integrity_strict" 2>/dev/null)}"
    _BOOT_INTEGRITY_STRICT="${_BOOT_INTEGRITY_STRICT:-1}"

    # _mount_integrity_halt <state> — write the halt marker (atomic temp+rename, so a
    # concurrent multi-tab halt cannot leave a partial file), log the critical
    # lifecycle event, surface the error, and exit 1. op_mount runs in a ( ) subshell,
    # so this exits op_mount with the halt code. ONE place for the halt mechanics.
    _mount_integrity_halt() {
        local _state="$1"
        local _halt_parent="/tmp/unraid-aicliagents/supervisor/halts/${TYPE}"
        mkdir -p "$_halt_parent" 2>/dev/null
        printf '%s' "$_state" > "${_halt_parent}/${ID}.tmp.$$" && mv "${_halt_parent}/${ID}.tmp.$$" "${_halt_parent}/${ID}"
        lifecycle_log "critical" "mount_stack" "mount_stack_halted" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"state\":\"$_state\"}" 2>/dev/null || true
        error "Boot integrity: ${_state} for ${TYPE}/${ID}. Mount halted. Open Settings > Storage to recover."
        exit 1
    }

    case "$_INTEGRITY_STATE" in
        healthy|genuine_fresh)
            log "No lower layers found for ${TYPE} ${ID}. Mounting empty stack (state: ${_INTEGRITY_STATE})."
            ;;
        legacy_unmanaged|total_loss)
            # F7 (WP#1329): these NEVER mount an empty stack over real data — halt
            # UNCONDITIONALLY, regardless of strict mode. legacy_unmanaged = unmigrated
            # data present (the sibling/backup recovery net); total_loss = the manifest
            # expected layers but none are on disk. Mounting empty here permanently
            # shadows the data (the first bake captures the empty overlay), so strict
            # mode must NOT be able to turn this protection off. "No protection removed."
            _mount_integrity_halt "$_INTEGRITY_STATE"
            ;;
        path_drift|partial_loss|corrupt_layers|host_mismatch)
            # Strict-gated: halt in strict mode, warn-and-proceed otherwise (Phase 4a).
            if [ "$_BOOT_INTEGRITY_STRICT" = "1" ]; then
                _mount_integrity_halt "$_INTEGRITY_STATE"
            fi
            error "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Mounting empty stack in warn mode (strict disabled)."
            ;;
        untracked)
            # Spec: quarantine + mount empty + warn -- never halt.
            log "Boot integrity: ${_INTEGRITY_STATE} for ${TYPE}/${ID}. Supervisor will attempt recovery. Mounting empty stack."
            ;;
        *)
            # F7 (WP#1329): unknown = the classifier was unavailable or errored (the
            # fail-closed default + the `|| echo unknown` on the call). Empty-mount
            # safety CANNOT be certified, so FAIL CLOSED and halt regardless of strict —
            # never silently mount empty over data we couldn't inspect.
            _mount_integrity_halt "$_INTEGRITY_STATE"
            ;;
    esac

    lifecycle_log "warn" "mount_stack" "mount_stack_fresh_install" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\",\"integrity_state\":\"$_INTEGRITY_STATE\"}" \
        2>/dev/null || true
    EMPTY_LOWER="/tmp/unraid-aicliagents/mnt/empty"
    mkdir -p "$EMPTY_LOWER"
    LOWERS="$EMPTY_LOWER"
fi

# WP #1263 (#7): serialise this overlay (re)mount against any other mount op for
# the same entity. The launch path (ensureHomeMounted -> op_mount), the post-bake
# reclaim refresh (op_bake -> op_mount) and the post-consolidate remount
# (op_consolidate -> op_mount) all pass through here, so one flock closes the
# launch-vs-reclaim race that the home_mount_in_use defer leaves open. flock -w 30
# bounds the wait so a wedged holder degrades to "proceed" rather than deadlocking
# a user launch (30s >> any real remount). The fd is held until this op_mount
# subshell exits, covering the umount+mount below. (mount_op_lock_path is from
# common.sh, sourced above.)
if [ "$_MOUNT_LOCK_HELD" != "1" ]; then
    { exec {_MOUNT_OP_LOCK_FD}>"$(mount_op_lock_path "$TYPE" "$ID")"; } 2>/dev/null || _MOUNT_OP_LOCK_FD=""
    if [ -n "${_MOUNT_OP_LOCK_FD:-}" ]; then
        flock -w 30 "$_MOUNT_OP_LOCK_FD" || log "mount-op lock wait timed out after 30s — proceeding (possible wedged holder)"
    fi
fi

# ============================================================================
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15): decide WHICH generation
# this call is binding, and where its writable layer lives, BEFORE anything is
# torn down.
#
# Phase 2 computed the generation AFTER the teardown arbiter had already emptied
# the one place an agent could be mounted, because there was only ever one. The
# whole point of Phase 3 is that there can now be two, so the order inverts: work
# out the target first, then tear down only what is genuinely in the way. A
# generation a live process is still running from is never in the way — it is a
# different directory, and leaving it mounted is the feature.
# ============================================================================
_AGENT_VERSIONED=0
_AGENT_GEN_ID=""
_AGENT_STABLE_LINK="$MNT_POINT"
_TEARDOWN_TARGET="$MNT_POINT"

if [ "$TYPE" = "agent" ] && [ -n "${FILES[0]:-}" ] \
   && declare -f agent_versioned_mount >/dev/null 2>&1 \
   && declare -f aicli_agent_generation_id >/dev/null 2>&1; then
    _AGENT_GEN_ID="$(aicli_agent_generation_id "${FILES[0]}" 2>/dev/null || true)"
fi

if [ -n "$_AGENT_GEN_ID" ]; then
    _AGENT_VERSIONED=1
    _AGENT_TARGET_MOUNT="$(agent_versioned_mount "$ID" "$_AGENT_GEN_ID")"
    _AGENT_LIVE_REAL="$(agent_mount_real "$ID" 2>/dev/null || echo "$MNT_POINT")"

    # --- Idempotent no-op -----------------------------------------------------
    # The newest layer set already has its generation bound AND activated. This
    # is the common case on every workspace launch, and Phase 2 reached it via
    # the arbiter's "busy -> defer" path, which reported a deferral for a mount
    # that was in fact perfectly correct. Say so plainly and succeed instead.
    if mountpoint -q "$_AGENT_TARGET_MOUNT" 2>/dev/null \
       && [ "$(agent_live_generation "$ID" 2>/dev/null || true)" = "$_AGENT_GEN_ID" ]; then
        log "Agent $ID is already on generation $_AGENT_GEN_ID (newest layer) — nothing to do."
        # Back-fill the state record for a generation the Phase 2 code bound.
        # Those generations predate the record and use the id-keyed writable
        # layer, so without this the reaper cannot tell which layer belongs to
        # them and has to leave it on flash for good — about 1 GB across this
        # box's agents after their first upgrade each. The layer is read out of
        # /proc/mounts, not derived: for these generations a derived answer would
        # name the versioned path they do not use. It also makes
        # agent_generation_upper_is_shared true while this generation is mounted,
        # which is what stops the reaper ever removing a layer still in service.
        if ! agent_generation_state_get "$ID" "$_AGENT_GEN_ID" upper >/dev/null 2>&1; then
            _BOUND_UPPER="$(agent_generation_bound_upper "$ID" "$_AGENT_GEN_ID" 2>/dev/null || true)"
            if [ -n "$_BOUND_UPPER" ]; then
                agent_generation_state_set "$ID" "$_AGENT_GEN_ID" upper "$_BOUND_UPPER" 2>/dev/null || true
                _BOUND_WORK="$(agent_generation_bound_upper "$ID" "$_AGENT_GEN_ID" workdir 2>/dev/null || true)"
                [ -n "$_BOUND_WORK" ] && agent_generation_state_set "$ID" "$_AGENT_GEN_ID" work "$_BOUND_WORK" 2>/dev/null || true
                agent_generation_state_set "$ID" "$_AGENT_GEN_ID" adopted_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" 2>/dev/null || true
                log "Agent $ID: recorded generation $_AGENT_GEN_ID's existing writable layer ($_BOUND_UPPER) so it can be reclaimed when superseded."
            fi
        fi
        # Sweep anyway. This is the path EVERY workspace launch takes once the
        # newest version is active, and it is the only moment the plugin reliably
        # revisits an agent after an upgrade. Without it a superseded generation —
        # a live overlay plus a few hundred megabytes of writable layer — would
        # survive until the NEXT upgrade of that agent, however long that is,
        # even though the session holding it ended minutes after the switch.
        _sweep_agent_generations "$ID" "$PERSIST_PATH"
        exit 0
    fi

    # --- What, if anything, has to come down ---------------------------------
    # Only the location currently behind the stable name, and only when it is
    # NOT the target. A referenced generation stays exactly where it is; that is
    # the entire feature. An UNreferenced one is torn down so superseded
    # generations do not accumulate on a write-endurance-limited stick.
    _AGENT_OLD_GEN="$(agent_live_generation "$ID" 2>/dev/null || true)"

    # Forgejo #296 (2026-09-23): a refresh-only caller (op_consolidate) must
    # never move the agent to another generation while the live generation's
    # writable layer holds data. The new generation gets a different, empty
    # writable layer, so the data drops out of the view the caller bakes.
    # Defer instead: the caller's fallback delta bake captures the layer
    # first, and that bake's own refresh then makes the switch safely.
    if [ "$_REFRESH_ONLY" = "1" ] && [ -n "$_AGENT_OLD_GEN" ] && [ "$_AGENT_OLD_GEN" != "$_AGENT_GEN_ID" ]; then
        _OLD_GEN_UPPER="$(agent_generation_state_get "$ID" "$_AGENT_OLD_GEN" upper 2>/dev/null || true)"
        [ -n "$_OLD_GEN_UPPER" ] || _OLD_GEN_UPPER="$(agent_generation_bound_upper "$ID" "$_AGENT_OLD_GEN" 2>/dev/null || true)"
        if _gen_switch_blocked "$_REFRESH_ONLY" "$_AGENT_OLD_GEN" "$_AGENT_GEN_ID" "$_OLD_GEN_UPPER"; then
            log "Agent $ID: refresh-only mount. Generation $_AGENT_OLD_GEN is live and its writable layer holds data that is not baked; newer layer generation $_AGENT_GEN_ID waits. Deferring — no generation switch."
            _op_defer "$TYPE" "$ID" "mount_stack" "mount_stack_generation_switch_deferred" "mount_busy" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"live\":\"$_AGENT_OLD_GEN\",\"target\":\"$_AGENT_GEN_ID\",\"reason\":\"refresh_only_generation_switch\"}"
        fi
    fi

    if [ "$_AGENT_LIVE_REAL" = "$_AGENT_TARGET_MOUNT" ]; then
        # Same generation, not yet mounted (or a half-failed earlier activation):
        # arbitrate the target itself, exactly as Phase 2 did.
        _TEARDOWN_TARGET="$_AGENT_TARGET_MOUNT"
    elif [ -n "$_AGENT_OLD_GEN" ] \
         && aicli_agent_generation_is_referenced "$ID" "$_AGENT_OLD_GEN" 2>/dev/null; then
        # SIDE BY SIDE. A live process is running from the old generation, so it
        # keeps its mount, its lower stack and its own upper, untouched, for as
        # long as it lives. We bind the new generation next to it and move the
        # stable name; every NEW session resolves the new one.
        log "Agent $ID: generation $_AGENT_OLD_GEN is still in use — mounting generation $_AGENT_GEN_ID beside it (no session closed). This is the mount of the newest layer set, not an install."
        lifecycle_log "info" "mount_stack" "agent_generation_side_by_side" \
            "{\"id\":\"$ID\",\"previous\":\"$_AGENT_OLD_GEN\",\"target\":\"$_AGENT_GEN_ID\"}" 2>/dev/null || true
        _TEARDOWN_TARGET="$_AGENT_TARGET_MOUNT"
    elif [ -L "$_AGENT_STABLE_LINK" ] || [ ! -e "$_AGENT_STABLE_LINK" ]; then
        # Already on the versioned layout (or nothing there yet) and the old
        # generation is unreferenced — tear it down now rather than leave it to
        # the GC sweep, so the overlap window is as short as the facts allow.
        if [ -n "$_AGENT_OLD_GEN" ] && [ "$_AGENT_OLD_GEN" != "$_AGENT_GEN_ID" ]; then
            log "Agent $ID: generation $_AGENT_OLD_GEN is unreferenced — releasing it before binding $_AGENT_GEN_ID."
            if ! _mount_teardown_arbiter "$_AGENT_LIVE_REAL"; then
                log "Agent $ID: could not release $_AGENT_OLD_GEN cleanly; it stays mounted and the GC sweep will retry."
            fi
        fi
        _TEARDOWN_TARGET="$_AGENT_TARGET_MOUNT"
    else
        # A genuinely pre-Phase-2 install: the stable name is still a REAL
        # mounted directory, so it cannot become a symlink while anything holds
        # it. Arbitrate it as Phase 1/2 did — busy defers, idle converts.
        _TEARDOWN_TARGET="$_AGENT_LIVE_REAL"
    fi
elif [ "$TYPE" = "agent" ] && declare -f agent_mount_real >/dev/null 2>&1; then
    # No baked layer yet (fresh install, empty lower stack) — Phase 1/2 shape,
    # byte-identical: arbitrate whatever the stable name resolves to.
    _TEARDOWN_TARGET="$(agent_mount_real "$ID")"
fi

# WP #1309: teardown-before-remount via the busy-arbiter (common.sh), SAFE BY
# CONSTRUCTION. The old code did `umount … || umount -l … || true` then re-bound
# the SAME upperdir/workdir — a lazy umount only detaches from the namespace and
# defers releasing upper/work, so the fresh overlay double-binds the same upper →
# copy-up poison (new-file create → ENOENT). The arbiter does a REAL umount only;
# on a BUSY mount it DEFERS (keep the live overlay — the upper holds all data,
# only the lower refresh waits for idle) or, for a phantom-and-busy mount, errors
# rather than perform an unsafe remount. The L-mount flock taken above (#1263)
# is still held across this decision.
# `if …; then` so op_mount's `set -e` does NOT fire on the arbiter's non-zero
# (defer/error) return before we capture it — a bare call would exit the subshell
# with the arbiter's code and SKIP the defer-reason marker + lifecycle event below.
# GH #9 self-diagnosis (2026-09-17): count the kernel's "in-use as upperdir/
# workdir" lines BEFORE the arbiter so the post-mount check below can tell
# whether THIS bind triggered one, and record whether the arbiter actually
# unmounted something (a remount that warns despite a real umount, with no
# lazy detach anywhere, is the case still unexplained after the lazy-detach fix).
# R-086: grep -c prints 0 AND exits 1 when nothing matches, so the old
# "|| echo 0" gave "0 newline 0" and the numeric compare below failed. Take the
# count from stdout only, and treat anything that is not a number as 0.
_dmesg_inuse_count() {
    local _n
    _n="$(dmesg 2>/dev/null | grep -c 'in-use as upperdir')" || true
    case "$_n" in ''|*[!0-9]*) _n=0 ;; esac
    printf '%s' "$_n"
}
_INUSE_BEFORE=$(_dmesg_inuse_count)
_WAS_MOUNTED=0; mountpoint -q "$_TEARDOWN_TARGET" 2>/dev/null && _WAS_MOUNTED=1
if _mount_teardown_arbiter "$_TEARDOWN_TARGET"; then
    _TEARDOWN_RC=0
else
    _TEARDOWN_RC=$?
fi
if [ "$_TEARDOWN_RC" -eq 2 ]; then
    log "Mount $_TEARDOWN_TARGET is BUSY (live overlay). Deferring lower refresh — upper holds all data; the new lower is picked up on the next idle refresh."
    _op_defer "$TYPE" "$ID" "mount_stack" "mount_stack_refresh_deferred_busy" "mount_busy" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$_TEARDOWN_TARGET\"}"
elif [ "$_TEARDOWN_RC" -eq 1 ]; then
    error "Mount $_TEARDOWN_TARGET is busy and NOT a healthy overlay — refusing unsafe remount (would poison copy-up)."
    lifecycle_log "error" "mount_stack" "mount_stack_busy_phantom" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$_TEARDOWN_TARGET\"}" 2>/dev/null || true
    exit 1
fi
# _TEARDOWN_RC == 0: released, or was never mounted — safe to bind a fresh overlay.

# #372: a home that moves to the policy upper. The old overlay is released now,
# so nothing can write to the old upper any more: check it again, then copy its
# directory skeleton (modes, owners, xattrs) so the view keeps every directory.
# If it holds data after all, or the copy fails, keep the old upper.
if [ "$_HOME_UPPER_CHOICE" = "switch" ]; then
    if _upper_holds_data "$_HOME_LIVE_UPPER" || ! _copy_dir_skeleton "$_HOME_LIVE_UPPER" "$UPPER_DIR"; then
        UPPER_DIR="$_HOME_LIVE_UPPER"
        WORK_DIR="${_HOME_LIVE_WORK:-$WORK_DIR}"
        ENTITY_UPPER_MODE="$(_upper_mode_of "$UPPER_DIR")"
        _HOME_UPPER_CHOICE="keep_live"
        log "Home $ID: the old upper $UPPER_DIR could not be moved safely (data arrived, or the directory copy failed) — keeping it."
        lifecycle_log "warn" "mount_stack" "home_upper_mode_kept_live" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"live_upper\":\"$UPPER_DIR\",\"policy_upper\":\"$_HOME_POLICY_UPPER\",\"reason\":\"switch_recheck\"}" 2>/dev/null || true
    else
        lifecycle_log "info" "mount_stack" "home_upper_mode_switched" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"from\":\"$_HOME_LIVE_UPPER\",\"to\":\"$UPPER_DIR\"}" 2>/dev/null || true
    fi
fi

# --- Bind target + this generation's own writable layer ----------------------
# Phase 3: each generation gets its OWN upper/work, so two live versions never
# share a writable layer. The one exception is the one-time migration off the
# single-version world: when NOTHING of this agent is mounted, the pre-Phase-3
# shared upper is renamed into this generation's versioned name. A rename is
# instantaneous and same-filesystem, so the 255-450 MB of un-baked install output
# measured in these uppers is carried over rather than copied or discarded — and
# it is only ever attempted when no mount can possibly be holding it.
if [ "$_AGENT_VERSIONED" = "1" ]; then
    _GEN_UPPER="$(agent_generation_state_get "$ID" "$_AGENT_GEN_ID" upper 2>/dev/null || true)"
    _GEN_WORK="$(agent_generation_state_get "$ID" "$_AGENT_GEN_ID" work 2>/dev/null || true)"
    if [ -n "$_GEN_UPPER" ] && [ -n "$_GEN_WORK" ]; then
        # This generation has been bound before — reuse the layer it already
        # owns. Never re-derived: a generation that adopted the legacy path must
        # keep it, and guessing would either lose its content or point two
        # generations at one tree.
        UPPER_DIR="$_GEN_UPPER"
        WORK_DIR="$_GEN_WORK"
    else
        _LEGACY_UPPER="$UPPER_DIR"
        _LEGACY_WORK="$WORK_DIR"
        _entity_paths "$TYPE" "$ID" "$PERSIST_PATH" "$_AGENT_GEN_ID"
        if [ "$(agent_generation_count "$ID")" -eq 0 ] \
           && ! mountpoint -q "$_AGENT_STABLE_LINK" 2>/dev/null \
           && [ -d "$_LEGACY_UPPER" ] && [ ! -e "$UPPER_DIR" ]; then
            if mv -T "$_LEGACY_UPPER" "$UPPER_DIR" 2>/dev/null; then
                [ -d "$_LEGACY_WORK" ] && { rm -rf "${UPPER_DIR%/*}/.workmigrate.$$" 2>/dev/null; mv -T "$_LEGACY_WORK" "$WORK_DIR" 2>/dev/null || true; }
                log "Agent $ID: adopted the pre-Phase-3 shared writable layer into generation $_AGENT_GEN_ID."
                lifecycle_log "info" "mount_stack" "agent_upper_migrated_to_generation" \
                    "{\"id\":\"$ID\",\"generation\":\"$_AGENT_GEN_ID\",\"from\":\"$_LEGACY_UPPER\"}" 2>/dev/null || true
            else
                log "Agent $ID: could not adopt the shared writable layer — generation $_AGENT_GEN_ID starts with a fresh one."
            fi
        fi
    fi
    mkdir -p "$UPPER_DIR" "$WORK_DIR" || { error "Failed to create writable layer for $ID generation $_AGENT_GEN_ID"; exit 1; }

    # MNT_POINT is assigned LAST, and deliberately so: _entity_paths above sets
    # MNT_POINT as well as the writable layer, so assigning the versioned target
    # any earlier has it silently reset to the stable path — the overlay then
    # binds over the stable name, the activation cannot replace a mounted
    # directory with a symlink, and the agent never reaches the versioned
    # layout at all. Caught end-to-end on 2026-09-15 rather than by reading.
    MNT_POINT="$_AGENT_TARGET_MOUNT"
    mkdir -p "$MNT_POINT" || { error "Failed to create versioned mount dir $MNT_POINT"; exit 1; }

    agent_generation_state_set "$ID" "$_AGENT_GEN_ID" upper "$UPPER_DIR" 2>/dev/null || true
    agent_generation_state_set "$ID" "$_AGENT_GEN_ID" work  "$WORK_DIR" 2>/dev/null || true
    agent_generation_state_set "$ID" "$_AGENT_GEN_ID" layer "$(basename -- "${FILES[0]}")" 2>/dev/null || true
    agent_generation_state_set "$ID" "$_AGENT_GEN_ID" bound_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" 2>/dev/null || true
fi
LAYER_COUNT=$(echo "$LOWERS" | tr ':' '\n' | wc -l)
# Forgejo #303: verify that the previous overlay on this writable layer is really
# gone before the bind. The arbiter released the target path, but another overlay
# (a mount stacked under it, or one at another path) can still name the same
# upper/work. Binding a second overlay on it is the copy-up-poison shape, so
# refuse — the same answer as a busy phantom above. Only a clean table binds.
if _upper_still_bound "$UPPER_DIR" "$WORK_DIR"; then
    error "Writable layer $UPPER_DIR is still bound by another overlay — refusing to bind it a second time (would poison copy-up)."
    lifecycle_log "error" "mount_stack" "mount_stack_upper_still_bound" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$MNT_POINT\",\"upper\":\"$UPPER_DIR\",\"arbiter_rc\":$_TEARDOWN_RC}" 2>/dev/null || true
    for _lm in "${_NEW_LOOP_MOUNTS[@]}"; do   # WP #1084: do not leak this call's loops
        umount "$_lm" 2>/dev/null || true
    done
    exit 1
fi
# Claude Code and other package managers finalize directory caches with rename(2).
# A directory originating in a SquashFS lower otherwise returns EXDEV; redirect_dir
# copies up its metadata and preserves the atomic rename contract.
if mount -t overlay overlay -o lowerdir="$LOWERS",upperdir="$UPPER_DIR",workdir="$WORK_DIR",redirect_dir=on "$MNT_POINT"; then
    log "Stack mounted at $MNT_POINT (Layers: $LAYER_COUNT)"
    # GH #9 self-diagnosis: did the kernel warn for THIS bind? If so, record
    # everything a human would otherwise have to reconstruct from three logs:
    # whether the arbiter really unmounted a previous overlay, how many mounts
    # now sit at the path (a stacked pair = the previous one was never
    # unmounted), and who holds the upper right now.
    _INUSE_AFTER=$(_dmesg_inuse_count)
    if [ "${_INUSE_AFTER:-0}" -gt "${_INUSE_BEFORE:-0}" ]; then
        _STACKED=$(awk -v m="$MNT_POINT" '$5==m{c++} END{print c+0}' /proc/self/mountinfo 2>/dev/null)
        _HOLDERS=""
        if command -v fuser >/dev/null 2>&1; then
            _HOLDERS=$(fuser -vm "$UPPER_DIR" 2>&1 | tr '\n' ' ' | tr -s ' ' | sed 's/["\\]/ /g' | cut -c1-600)
        fi
        log "WARNING: kernel logged 'upperdir/workdir is in-use' for this bind (was_mounted=$_WAS_MOUNTED arbiter_rc=$_TEARDOWN_RC mounts_at_path=$_STACKED). holders of $UPPER_DIR: ${_HOLDERS:-none}"
        lifecycle_log "warn" "mount_stack" "overlay_inuse_warning_after_mount" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$MNT_POINT\",\"was_mounted\":$_WAS_MOUNTED,\"arbiter_rc\":$_TEARDOWN_RC,\"mounts_at_path\":${_STACKED:-0},\"layer_count\":$LAYER_COUNT,\"holders\":\"${_HOLDERS:-none}\"}" 2>/dev/null || true
    fi
    # Any prior busy snapshot is now part of this freshly assembled lower stack
    # and must never be considered replaceable by a later live-session bake.
    if [ "$TYPE" = "home" ]; then
        _MOUNT_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
        rm -f "/tmp/unraid-aicliagents/.bake_busy_snapshot_${TYPE}_${_MOUNT_LOCK_ID}" 2>/dev/null || true
    fi
    lifecycle_log "info" "mount_stack" "mount_stack_assembled" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"layer_count\":$LAYER_COUNT,\"mount_point\":\"$MNT_POINT\"}" 2>/dev/null || true
    if [ "$_AGENT_VERSIONED" = "1" ]; then
        # Flip the stable symlink onto the generation we just mounted. Only now
        # — after `mount` above has already succeeded — so a new session can
        # never resolve agent_mount to a generation whose overlay isn't live
        # yet (mirrors aicli_activate_generation never flipping `src` until its
        # own payload is staged). R-022 (#600): a failure here FAILS the mount
        # (exit 1). The overlay is mounted, but the stable name still points at
        # the old generation or at an empty folder, so a session would start
        # with no binary; no caller retries a mount that reported success.
        if agent_activate_stable_symlink "$ID" "$_AGENT_GEN_ID" "$MNT_POINT"; then
            lifecycle_log "info" "mount_stack" "agent_generation_activated" \
                "{\"id\":\"$ID\",\"generation\":\"$_AGENT_GEN_ID\"}" 2>/dev/null || true
            # Phase 3: sweep superseded generations now that the stable name has
            # moved. Reaps only what NO live process names, so the generation an
            # older session is still running from survives this call by design
            # and is collected by a later sweep once that session ends. Runs on
            # every activation (not only at install time) because a session can
            # end at any moment and an agent generation costs 100-450 MB of the
            # writable layer, an order of magnitude more than the plugin's own.
            _sweep_agent_generations "$ID" "$PERSIST_PATH"
        else
            error "Mounted generation $_AGENT_GEN_ID for agent $ID but failed to activate the stable symlink at $_AGENT_STABLE_LINK — the mount is reported as failed."
            lifecycle_log "error" "mount_stack" "agent_stable_symlink_failed" \
                "{\"id\":\"$ID\",\"generation\":\"$_AGENT_GEN_ID\",\"link\":\"$_AGENT_STABLE_LINK\"}" 2>/dev/null || true
            exit 1
        fi
    fi
else
    error "Failed to mount OverlayFS stack at $MNT_POINT"
    # WP #1084: overlay mount failed — clean up the loop mounts we just made
    # so they don't leak as orphans pointing at (potentially since-deleted)
    # sqsh files. Pre-existing loop mounts are left alone.
    for _lm in "${_NEW_LOOP_MOUNTS[@]}"; do
        umount_or_keep "$_lm" "new layer mount" 2 || true   # #512: never lazy
    done
    lifecycle_log "error" "mount_stack" "mount_stack_failed_loops_cleaned" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"new_loops_cleaned\":${#_NEW_LOOP_MOUNTS[@]}}" 2>/dev/null || true
    exit 1
fi
)

# ---- direct-agent generation helpers ----------------------------------------
#
# The layering backend gets side-by-side installs from versioned overlay mounts.
# The plain-directory backend uses the same stable-link idea without inventing a
# SquashFS layer: the live directory is retained under .versions, the installer
# writes a copied successor under .staging-data, and bake --staged atomically
# points the stable persistence link at that successor. Existing bind mounts keep
# the old directory inode, so open sessions continue to run the old version.
_pt_agent_stable_dir() { printf '%s\n' "$1/passthrough/agents/$2"; }
_pt_agent_versions_dir() { printf '%s\n' "$1/passthrough/agents/.versions/$2"; }
_pt_agent_stage_dir() { printf '%s\n' "$1/passthrough/agents/.staging-data/$2"; }

_pt_agent_prepare_versioned() (
set -euo pipefail
local persist="$1" id="$2" stable versions legacy link_tmp
stable="$(_pt_agent_stable_dir "$persist" "$id")"
versions="$(_pt_agent_versions_dir "$persist" "$id")"
mkdir -p "$(dirname "$stable")" "$versions"

if [ -L "$stable" ]; then
    case "$(readlink "$stable" 2>/dev/null || true)" in
        .versions/"$id"/*) exit 0 ;;
        *) printf '%s\n' '[direct-stage] refusing an unexpected persistence symlink' >&2; exit 1 ;;
    esac
fi

if [ -e "$stable" ]; then
    [ -d "$stable" ] || { printf '%s\n' '[direct-stage] persistence target is not a directory' >&2; exit 1; }
    mountpoint -q "$stable" 2>/dev/null && { printf '%s\n' '[direct-stage] persistence directory is unexpectedly mounted' >&2; exit 2; }
    legacy="legacy-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mv "$stable" "$versions/$legacy"
else
    legacy="initial-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mkdir -p "$versions/$legacy"
fi

link_tmp="${stable}.next.$$"
rm -f "$link_tmp" 2>/dev/null || true
ln -s ".versions/$id/$legacy" "$link_tmp"
mv -Tf "$link_tmp" "$stable"
)

_pt_agent_stage() (
set -euo pipefail
local id="$1" persist="$2" stable current stage_dir stage_mnt lock_id
lock_id="${id//[^a-zA-Z0-9_-]/_}"
exec 8>"/var/run/aicli-direct-stage-${lock_id}.lock"
flock -n 8 || { write_defer_reason agent "$id" "direct_stage_lock"; exit 2; }
guard_path "$persist" "PERSIST_PATH" || exit 1
_assert_persist_durable "$persist" || exit 1
_pt_agent_prepare_versioned "$persist" "$id"
stable="$(_pt_agent_stable_dir "$persist" "$id")"
current="$(readlink -f "$stable" 2>/dev/null || true)"
[ -n "$current" ] && [ -d "$current" ] || { printf '%s\n' '[direct-stage] active directory is missing' >&2; exit 1; }
# 2026-09-26 (.4, /boot at 100 %): the staged copy of the version in service
# and the new version the installer writes beside its download both land on the
# persist drive. Never START a stage that cannot fit — a write that fills the
# flash failed antigravity's extraction and the settings saves with it.
# Estimate: twice the version in service (the copy + the new version).
local cur_bytes need_bytes
cur_bytes="$(du -sb "$current" 2>/dev/null | awk '{print $1}')"
case "$cur_bytes" in ''|*[!0-9]*) cur_bytes=0 ;; esac
need_bytes=$(( cur_bytes * 2 ))
# BOOT_DISK_SPACE.md R3: a FIRST install has no version in service to measure
# (cur_bytes 0), and on an internal boot it now lands in plain folders. An agent
# unpacks to 100 to 450 MB: never start one with less than this floor free.
if [ "$cur_bytes" -eq 0 ]; then
    _nf_mb="${AICLI_NEW_AGENT_MIN_MB:-300}"
    case "$_nf_mb" in ''|*[!0-9]*) _nf_mb=300 ;; esac
    need_bytes=$(( _nf_mb * 1048576 ))
fi
if ! _layer_fits_space "$persist" "$need_bytes"; then
    printf '[direct-stage] no space on the boot disk for the new version of %s: about %s MB needed, %s MB free — install not started. Open Home Storage, Boot disk space to see what uses the space; turn on Keep agents compressed (Configuration) to keep agents in compressed layers.\n' \
        "$id" "$_FIT_NEED_MB" "$_FIT_FREE_MB" >&2
    lifecycle_log "error" "stage" "direct_agent_stage_no_space" \
        "{\"id\":\"$id\",\"need_mb\":$_FIT_NEED_MB,\"free_mb\":\"$_FIT_FREE_MB\",\"current_bytes\":$cur_bytes}" 2>/dev/null || true
    write_defer_reason agent "$id" "no_space"
    exit 2
fi
stage_dir="$(_pt_agent_stage_dir "$persist" "$id")"
stage_mnt="$(agent_staging_mount "$id")"
if mountpoint -q "$stage_mnt" 2>/dev/null; then
    umount "$stage_mnt" 2>/dev/null || { printf '%s\n' '[direct-stage] previous staging mount is busy' >&2; exit 2; }
fi
guard_path "$stage_dir" "direct staging directory" || exit 1
rm -rf "${stage_dir:?}" 2>/dev/null || true
mkdir -p "$stage_dir" "$stage_mnt"
if command -v rsync >/dev/null 2>&1; then
    rsync -aHAX --numeric-ids "$current/." "$stage_dir/"
else
    cp -a "$current/." "$stage_dir/"
fi
if ! mount --bind "$stage_dir" "$stage_mnt" 2>/dev/null; then
    printf '%s\n' '[direct-stage] could not bind the staging directory' >&2
    exit 1
fi
lifecycle_log "info" "stage" "direct_agent_install_staged" \
    "{\"id\":\"$id\",\"mount\":\"$stage_mnt\",\"source\":\"$stage_dir\"}" 2>/dev/null || true
printf '%s\n' "$stage_mnt"
)

op_promote_passthrough_stage() (
set -euo pipefail
local id="$1" persist="$2" stable versions stage_dir stage_mnt gen link_tmp lock_id
lock_id="${id//[^a-zA-Z0-9_-]/_}"
exec 8>"/var/run/aicli-direct-stage-${lock_id}.lock"
flock -n 8 || { write_defer_reason agent "$id" "direct_stage_lock"; exit 2; }
guard_path "$persist" "PERSIST_PATH" || exit 1
_assert_persist_durable "$persist" || exit 1
_pt_agent_prepare_versioned "$persist" "$id"
stable="$(_pt_agent_stable_dir "$persist" "$id")"
versions="$(_pt_agent_versions_dir "$persist" "$id")"
stage_dir="$(_pt_agent_stage_dir "$persist" "$id")"
stage_mnt="$(agent_staging_mount "$id")"
if mountpoint -q "$stage_mnt" 2>/dev/null; then
    umount "$stage_mnt" 2>/dev/null || { write_defer_reason agent "$id" "direct_stage_mount_busy"; exit 2; }
fi
[ -d "$stage_dir" ] || { printf '%s\n' '[direct-stage] staged directory is missing' >&2; exit 3; }
gen="direct-$(date -u +%Y%m%dT%H%M%SZ)-$$"
mv "$stage_dir" "$versions/$gen"
link_tmp="${stable}.next.$$"
rm -f "$link_tmp" 2>/dev/null || true
ln -s ".versions/$id/$gen" "$link_tmp"
mv -Tf "$link_tmp" "$stable"
lifecycle_log "info" "backend_migrate" "direct_agent_generation_promoted" \
    "{\"type\":\"agent\",\"id\":\"$id\",\"generation\":\"$gen\"}" 2>/dev/null || true
exit 0
)

# ---- op_stage / op_unstage  (SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3) --------
#
# op_stage <agent_id> <persist_path>
#
# Bind an install-only overlay for ONE agent: the same read-only lower stack the
# live version is serving from, plus a writable layer of its own, mounted at
# agent_staging_mount. The installer writes the new version in there and nothing
# that is running can see it.
#
# This is the piece that makes an upgrade stop needing a closed session set. It
# is deliberately NOT a generation: it has no entry under agent_versions_dir, the
# stable symlink never points at it, and no session can ever resolve to it. It
# exists only between "start installing" and "bake what was installed", and
# op_unstage removes it either way.
#
# Prints the staging mount path on stdout (the ONLY thing on stdout) so the
# caller can hand it to the installer. Exit 0 mounted, 1 could not.
op_stage() (
set -euo pipefail
ID="${1:-}"
PERSIST_PATH="${2:-}"
[ -n "$ID" ] && [ -n "$PERSIST_PATH" ] || { echo "Usage: op_stage <agent_id> <persist_path>" >&2; exit 1; }

PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Source shared storage functions (guard_path, check_disk_space, _entity_paths,
# _layer_discover_sorted) and the canonical path resolver. Same optional-source
# shape as op_mount's: resolve_paths.sh carries agent_staging_mount, without
# which staging cannot be located at all — so that one is required, not optional.
source "$_SO_DIR/common.sh"
source "$_SO_DIR/resolve_paths.sh" || { echo "resolve_paths.sh missing — cannot stage" >&2; exit 1; }


log()   { local m="[$(get_ts)] [INFO] [STAGE] $(_trace_tag)$1"; echo "$m" >&2; echo "$m" >> "$DEBUG_LOG"; }
error() { local m="[$(get_ts)] [ERR!] [STAGE] $(_trace_tag)$1"; echo "$m" >&2; echo "$m" >> "$DEBUG_LOG"; }

# Plain directories use a copied successor plus an atomic stable-link switch;
# they do not need an overlay or a SquashFS bake.
if [ "${AICLI_ITEST_BACKEND:-}" = "passthrough" ] \
   || { declare -f effective_backend >/dev/null 2>&1 && [ "$(effective_backend agent "$ID" "$PERSIST_PATH" 2>/dev/null)" = "passthrough" ]; }; then
    _pt_agent_stage "$ID" "$PERSIST_PATH"
    exit $?
fi

_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — staging refused"; exit 1; }

STAGE_MNT="$(agent_staging_mount "$ID")"
STAGE_KEY="$(agent_staging_entity_key "$ID")"
_entity_paths agent "$STAGE_KEY" "$PERSIST_PATH"
STAGE_UPPER="$UPPER_DIR"
STAGE_WORK="$WORK_DIR"
[ "$ENTITY_UPPER_MODE" = "zram" ] && { bash "$PLUGIN_ROOT/src/scripts/storage/initialize_zram.sh" || { error "ZRAM initialization failed"; exit 1; }; }

# Never inherit a previous, abandoned staging attempt: a half-finished install
# left in the layer would be baked into the new version as if it belonged there.
if mountpoint -q "$STAGE_MNT" 2>/dev/null; then
    umount "$STAGE_MNT" 2>/dev/null || { error "A previous staging mount at $STAGE_MNT is still busy — refusing to stage over it"; exit 1; }
fi
guard_path "$STAGE_UPPER" "staging upper" || { error "Refusing to clear an unsafe staging upper path"; exit 1; }
guard_path "$STAGE_WORK" "staging work" || { error "Refusing to clear an unsafe staging work path"; exit 1; }
rm -rf "${STAGE_UPPER:?}" "${STAGE_WORK:?}" 2>/dev/null || true
mkdir -p "$STAGE_UPPER" "$STAGE_WORK" "$STAGE_MNT" || { error "Could not create the staging layer for $ID"; exit 1; }

# The SAME lower stack the live version is serving. A new version installed over
# the current one is what every previous upgrade produced too — the difference is
# only that the result now lands in a layer nothing is reading.
# #338: the same stack cut as op_mount — ends at the newest base.
FILES=()
mapfile -t FILES < <(_layer_stack_sorted "$PERSIST_PATH" "agent" "$ID")
LOWERS=""
for sqsh in "${FILES[@]}"; do
    SQSH_NAME="$(basename "$sqsh" .sqsh)"
    SQSH_MNT="/tmp/unraid-aicliagents/mnt/$SQSH_NAME"
    mkdir -p "$SQSH_MNT"
    if ! mountpoint -q "$SQSH_MNT"; then
        mount -o loop,ro "$sqsh" "$SQSH_MNT" || { error "Failed to mount lower layer $sqsh"; exit 1; }
    fi
    [ -n "$LOWERS" ] && LOWERS="$LOWERS:"
    LOWERS="$LOWERS$SQSH_MNT"
done
if [ -z "$LOWERS" ]; then
    # A first-ever install has nothing to stack on. An empty lower is correct
    # here and needs none of op_mount's boot-integrity classification: this
    # overlay is not, and never becomes, the agent's data.
    LOWERS="/tmp/unraid-aicliagents/mnt/empty"
    mkdir -p "$LOWERS"
fi

if ! mount -t overlay overlay -o lowerdir="$LOWERS",upperdir="$STAGE_UPPER",workdir="$STAGE_WORK",redirect_dir=on "$STAGE_MNT"; then
    error "Failed to mount the staging overlay at $STAGE_MNT"
    exit 1
fi
log "Staged $ID for install at $STAGE_MNT (writable layer: $STAGE_UPPER)"
lifecycle_log "info" "stage" "agent_install_staged" \
    "{\"id\":\"$ID\",\"mount\":\"$STAGE_MNT\",\"upper\":\"$STAGE_UPPER\",\"layers\":${#FILES[@]}}" 2>/dev/null || true
printf '%s\n' "$STAGE_MNT"
)

# op_unstage <agent_id> <persist_path> [keep_upper]
#
# Tear the staging overlay down. With keep_upper=1 the writable layer is left on
# disk for the caller to bake; otherwise it is removed too. Safe to call when
# nothing is staged — that is a clean no-op, because the install failure path
# calls it unconditionally.
op_unstage() (
set -euo pipefail
ID="${1:-}"
PERSIST_PATH="${2:-}"
KEEP_UPPER="${3:-0}"
[ -n "$ID" ] && [ -n "$PERSIST_PATH" ] || { echo "Usage: op_unstage <agent_id> <persist_path> [keep_upper]" >&2; exit 1; }

PLUGIN_ROOT="/usr/local/emhttp/plugins/unraid-aicliagents"

# Source shared storage functions (guard_path, check_disk_space, _entity_paths,
# _layer_discover_sorted) and the canonical path resolver. Same optional-source
# shape as op_mount's: resolve_paths.sh carries agent_staging_mount, without
# which staging cannot be located at all — so that one is required, not optional.
source "$_SO_DIR/common.sh"
source "$_SO_DIR/resolve_paths.sh" || { echo "resolve_paths.sh missing — cannot stage" >&2; exit 1; }


log() { local m="[$(get_ts)] [INFO] [STAGE] $(_trace_tag)$1"; echo "$m" >&2; echo "$m" >> "$DEBUG_LOG"; }

if [ "${AICLI_ITEST_BACKEND:-}" = "passthrough" ] \
   || { declare -f effective_backend >/dev/null 2>&1 && [ "$(effective_backend agent "$ID" "$PERSIST_PATH" 2>/dev/null)" = "passthrough" ]; }; then
    STAGE_MNT="$(agent_staging_mount "$ID")"
    if mountpoint -q "$STAGE_MNT" 2>/dev/null; then
        umount "$STAGE_MNT" 2>/dev/null || { log "Direct staging mount $STAGE_MNT is still busy — leaving it for the next sweep."; exit 2; }
    fi
    if [ "$KEEP_UPPER" != "1" ]; then
        STAGE_DIR="$(_pt_agent_stage_dir "$PERSIST_PATH" "$ID")"
        guard_path "$STAGE_DIR" "direct staging directory" && rm -rf "${STAGE_DIR:?}" 2>/dev/null || true
    fi
    rmdir "$STAGE_MNT" 2>/dev/null || true
    lifecycle_log "info" "stage" "direct_agent_install_unstaged" "{\"id\":\"$ID\"}" 2>/dev/null || true
    exit 0
fi

STAGE_MNT="$(agent_staging_mount "$ID")"
STAGE_KEY="$(agent_staging_entity_key "$ID")"
_entity_paths agent "$STAGE_KEY" "$PERSIST_PATH"

if mountpoint -q "$STAGE_MNT" 2>/dev/null; then
    # REAL umount only. A lazy detach would return success while the kernel still
    # held the layer, and the rm below would then race it — the copy-up poison
    # WP #1309 already paid for once.
    if ! umount "$STAGE_MNT" 2>/dev/null; then
        log "Staging mount $STAGE_MNT is still busy — leaving it for the next sweep."
        exit 2
    fi
fi
rmdir "$STAGE_MNT" 2>/dev/null || true

if [ "$KEEP_UPPER" != "1" ]; then
    if guard_path "$UPPER_DIR" "staging upper" && guard_path "$WORK_DIR" "staging work"; then
        rm -rf "${UPPER_DIR:?}" "${WORK_DIR:?}" 2>/dev/null || true
    fi
    lifecycle_log "info" "stage" "agent_install_unstaged" "{\"id\":\"$ID\"}" 2>/dev/null || true
fi
exit 0
)

# ---- op_backend_migrate (global STORAGE_BACKEND_POLICY) ---------------------
# Convert one entity for the global engine switch. The normal graduate path is
# reused for layering -> passthrough (including its verified copy, write-ahead
# intent, manifest authority flip and retained .graduated rollback layer). The
# reverse path writes a verified SquashFS layer from the plain directory. A
# rollback restores the representation saved by this migration instead of
# baking a replacement copy.
backend_migration_check_layer_space() {
    local type="${1:-}" id="${2:-}" persist="${3:-}" source="${4:-}"
    local source_bytes need_mb available_mb
    source_bytes="$(du -sb "$source" 2>/dev/null | awk 'NR==1 {print $1}')"
    case "$source_bytes" in
        ''|*[!0-9]*)
            write_defer_reason "$type" "$id" "space_estimate_unavailable"
            echo "[backend-migrate] Cannot measure the plain folder before creating its SquashFS layer." >&2
            return 1
            ;;
    esac
    need_mb=$(( (source_bytes + source_bytes / 10 + 1048575) / 1048576 + BACKEND_MIGRATION_BAKE_MARGIN_MB ))
    available_mb="$(df -Pm "$persist" 2>/dev/null | awk 'NR==2 {print $4}')"
    case "$available_mb" in
        ''|*[!0-9]*)
            write_defer_reason "$type" "$id" "space_estimate_unavailable"
            echo "[backend-migrate] Cannot measure free space before creating the SquashFS layer. No storage was changed." >&2
            return 1
            ;;
    esac
    if [ "$available_mb" -lt "$need_mb" ]; then
        write_defer_reason "$type" "$id" "boot_disk_too_small"
        echo "[backend-migrate] The boot disk has too little room for the new layer (about ${need_mb} MB needed, ${available_mb} MB available). No storage was changed." >&2
        lifecycle_log "error" "backend_migrate" "backend_migrate_no_space" \
            "{\"type\":\"$type\",\"id\":\"$id\",\"source_bytes\":$source_bytes,\"need_mb\":$need_mb,\"available_mb\":$available_mb}" 2>/dev/null || true
        return 2
    fi
    return 0
}

op_backend_migrate() (
set -euo pipefail
TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
TARGET="${4:-}"
FORCE_DIRECT="${5:-0}"
ROLLBACK="${6:-0}"

source "$_SO_DIR/common.sh"
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || true

if [ "$TARGET" != "layering" ] && [ "$TARGET" != "passthrough" ]; then
    printf '%s\n' '[backend-migrate] invalid target engine' >&2
    exit 64
fi
[ -n "$TYPE" ] && [ -n "$ID" ] && [ -n "$PERSIST_PATH" ] || exit 64
guard_path "$PERSIST_PATH" "PERSIST_PATH" || exit 1
_assert_persist_durable "$PERSIST_PATH" || exit 1

_BM_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
backend_migration_clear_reasons "$TYPE" "$ID" "${AICLI_BACKEND_MIGRATION_MARKER_DIR:-/tmp/unraid-aicliagents}"
_BM_MNT=""
if [ "$TYPE" = "home" ]; then
    _BM_MNT="$(home_mount "$ID")"
elif declare -f agent_mount_real >/dev/null 2>&1; then
    _BM_MNT="$(agent_mount_real "$ID")"
else
    _BM_MNT="$(agent_mount "$ID")"
fi
_BM_PT="$PERSIST_PATH/passthrough/${TYPE}s/$ID"

_backend_migration_mark_changed() {
    local result_file="${AICLI_BACKEND_MIGRATE_RESULT_FILE:-}"
    [ -n "$result_file" ] || return 0
    printf '1\n' > "$result_file"
}

backend_migration_restore_archived_plain() {
    local base="$1" archive="$2" plain="$2/plain" link="$2/stable-link" link_target="" target_dir=""
    [ -d "$plain" ] || return 1
    if [ -L "$link" ]; then
        link_target="$(readlink -- "$link" 2>/dev/null || true)"
        [ -n "$link_target" ] || return 1
        case "$link_target" in
            /*) target_dir="$link_target" ;;
            *) target_dir="$base/$link_target" ;;
        esac
        base="$(realpath -m -- "$base" 2>/dev/null || true)"
        target_dir="$(realpath -m -- "$target_dir" 2>/dev/null || true)"
        [ -n "$base" ] && [ -n "$target_dir" ] || return 1
        case "$target_dir" in "$base"/*) : ;; *) return 1 ;; esac
        [ ! -e "$target_dir" ] || return 1
        mkdir -p "$(dirname "$target_dir")" || return 1
        mv "$plain" "$target_dir" || return 1
        mv "$link" "$_BM_PT" || return 1
    else
        mv "$plain" "$_BM_PT" || return 1
    fi
    manifest_set_backend "$TYPE" "$ID" "passthrough" || return 1
    while IFS= read -r _bm_layer; do
        [ -n "$_bm_layer" ] || continue
        rm -f -- "$_bm_layer" || return 1
    done < <(layer_files_of "$PERSIST_PATH" "$TYPE" "$ID")
    rm -rf -- "$archive" || return 1
    return 0
}

if [ "$TARGET" = "passthrough" ]; then
    if [ "$ROLLBACK" = "1" ]; then
        _BM_AGENT_BASE="$PERSIST_PATH/passthrough/${TYPE}s"
        _BM_ARCHIVE_ROOT="$PERSIST_PATH/.backend-migration-archive"
        _BM_ARCHIVE_RESTORED=0
        if [ -d "$_BM_ARCHIVE_ROOT" ]; then
            while IFS= read -r _bm_archive; do
                [ -n "$_bm_archive" ] || continue
                if backend_migration_restore_archived_plain "$_BM_AGENT_BASE" "$_bm_archive"; then
                    _BM_ARCHIVE_RESTORED=1
                    break
                fi
            done < <(find "$_BM_ARCHIVE_ROOT" -mindepth 1 -maxdepth 1 -type d -name "*_${TYPE}_${_BM_LOCK_ID}" -print | sort -r)
        fi
        if [ "$_BM_ARCHIVE_RESTORED" = "1" ]; then
            lifecycle_log "info" "backend_migrate" "backend_migrate_rollback_restored_plain" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
            _backend_migration_mark_changed
            exit 0
        fi
    fi
    # Capture any last upper writes before reusing the battle-tested graduate
    # authority flip. This branch is idempotent for an already-direct entity.
    _BM_HAS_LAYERS="$(_entity_has_layers "$PERSIST_PATH" "$TYPE" "$ID")"
    if [ "$_BM_HAS_LAYERS" = "1" ]; then
        op_graduate "$TYPE" "$ID" "$PERSIST_PATH" "$FORCE_DIRECT" || exit $?
        _backend_migration_mark_changed
        exit 0
    fi
    _entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"
    if [ -d "$UPPER_DIR" ] && [ -n "$(find "$UPPER_DIR" -type f -print -quit 2>/dev/null)" ]; then
        op_bake "$TYPE" "$ID" "$PERSIST_PATH" || exit $?
        op_graduate "$TYPE" "$ID" "$PERSIST_PATH" "$FORCE_DIRECT" || exit $?
        _backend_migration_mark_changed
        exit 0
    fi
    # No layers and no saved upper data means there is nothing to convert.
    # In particular, do not create an empty plain folder that rollback can bake.
    exit 0
fi

# layering target: a plain directory is the current authority. Keep it in place
# until a verified layer and both manifest writes have succeeded.
if [ ! -d "$_BM_PT" ]; then
    exit 0
fi

# A layering rollback restores the exact layer set retained by the forward
# graduation. Rename it back; do not bake the copied plain tree a second time.
_BM_RETIRE="$PERSIST_PATH/.graduated/${TYPE}_${_BM_LOCK_ID}"
_BM_INTENT_PATH=""
if declare -f _intent_path >/dev/null 2>&1; then
    _BM_INTENT_PATH="$(_intent_path "$PERSIST_PATH" "$TYPE" "$ID")"
fi
if [ -f "$_BM_INTENT_PATH" ] && command -v jq >/dev/null 2>&1; then
    _BM_INTENT_RETIRE="$(jq -r '.retire // empty' "$_BM_INTENT_PATH" 2>/dev/null || true)"
    if [ -n "$_BM_INTENT_RETIRE" ]; then
        case "$_BM_INTENT_RETIRE" in
            /*) _BM_INTENT_RETIRE_PATH="$_BM_INTENT_RETIRE" ;;
            *) _BM_INTENT_RETIRE_PATH="$PERSIST_PATH/$_BM_INTENT_RETIRE" ;;
        esac
        case "$_BM_INTENT_RETIRE_PATH" in
            "$PERSIST_PATH"/*) [ -d "$_BM_INTENT_RETIRE_PATH" ] && _BM_RETIRE="$_BM_INTENT_RETIRE_PATH" ;;
        esac
    fi
fi
if [ ! -d "$_BM_RETIRE" ] || ! compgen -G "$_BM_RETIRE/*.sqsh" >/dev/null; then _BM_RETIRE=""; fi
if [ -n "$_BM_RETIRE" ]; then
    _entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"
    convert_rollback "$PERSIST_PATH" "$_BM_PT" "$_BM_RETIRE" "${UPPER_DIR:-}" || exit $?
    mapfile -t _BM_RESTORED_LAYERS < <(layer_files_of "$PERSIST_PATH" "$TYPE" "$ID")
    [ "${#_BM_RESTORED_LAYERS[@]}" -gt 0 ] || exit 1
    manifest_replace_layers "$TYPE" "$ID" "$PERSIST_PATH" "$(basename "${_BM_RESTORED_LAYERS[0]}")" || exit 1
    manifest_set_backend "$TYPE" "$ID" "flash" || exit 1
    lifecycle_log "info" "backend_migrate" "backend_migrate_rollback_restored_layers" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"layer\":\"$(basename "${_BM_RESTORED_LAYERS[0]}")\"}" 2>/dev/null || true
    _backend_migration_mark_changed
    exit 0
fi

_BM_PRECHECK_SRC="$(readlink -f -- "$_BM_PT" 2>/dev/null || true)"
if [ -n "$_BM_PRECHECK_SRC" ] && [ -d "$_BM_PRECHECK_SRC" ] \
    && [ -z "$(find "$_BM_PRECHECK_SRC" -mindepth 1 -print -quit 2>/dev/null)" ]; then
    # Avoid taking a bake lock or making a layer for an empty plain folder.
    exit 0
fi

_BM_BAKE_LOCK_DIR="${AICLI_BAKE_LOCK_DIR:-/var/run}"
exec 9>"$_BM_BAKE_LOCK_DIR/aicli-bake-${TYPE}-${_BM_LOCK_ID}.lock"
if ! flock -n 9; then
    write_defer_reason "$TYPE" "$ID" "backend_migrate_deferred"
    exit 2
fi

# 2026-09-29 (SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-29 — a consolidate reads
# the real directory, never the stable name"): a side-by-side plain-folder
# agent's stable path is a SYMLINK to its version directory (see the archive
# step below). mksquashfs packs a symlink source as the link alone, so bake
# the directory the link resolves to.
_BM_BAKE_SRC="$(readlink -f -- "$_BM_PT" 2>/dev/null || true)"
if [ -z "$_BM_BAKE_SRC" ] || [ ! -d "$_BM_BAKE_SRC" ]; then
    printf '%s\n' "[backend-migrate] $_BM_PT does not resolve to a directory — nothing changed" >&2
    exit 1
fi
if [ -z "$(find "$_BM_BAKE_SRC" -mindepth 1 -print -quit 2>/dev/null)" ]; then
    # Empty plain folders have no content to migrate and must not create an
    # empty SquashFS replacement during the forward step or its rollback.
    exit 0
fi
if backend_migration_check_layer_space "$TYPE" "$ID" "$PERSIST_PATH" "$_BM_BAKE_SRC"; then
    :
else
    exit $?
fi
_BM_ARCHIVE="$PERSIST_PATH/.backend-migration-archive/$(date -u +%Y%m%dT%H%M%SZ)_${TYPE}_${_BM_LOCK_ID}"
mkdir -p "$_BM_ARCHIVE" 2>/dev/null || exit 1
_BM_NEW_LAYER="$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$_BM_BAKE_SRC" "consolidated")" || exit 1

# `replaceLayers` makes the new verified file the only expected layer before
# anything is moved. The existing plain directory remains a complete rollback
# source if the process dies before the authority flip.
manifest_replace_layers "$TYPE" "$ID" "$PERSIST_PATH" "$_BM_NEW_LAYER" || exit 1
manifest_set_backend "$TYPE" "$ID" "flash" || exit 1

# Retain both old representations for recovery/audit. The new layer is the only
# live authority; the archive is deliberately outside layer discovery.
while IFS= read -r _old; do   # R-002 (#580): exact entity only
    [ -n "$_old" ] || continue
    [ "$(basename "$_old")" = "$_BM_NEW_LAYER" ] && continue
    mv -f "$_old" "$_BM_ARCHIVE/" || exit 1
done < <(layer_files_of "$PERSIST_PATH" "$TYPE" "$ID")
_BM_PT_REAL="$(readlink -f "$_BM_PT" 2>/dev/null || true)"
if [ -n "$_BM_PT_REAL" ] && [ "$_BM_PT_REAL" != "$_BM_PT" ] && [ -d "$_BM_PT_REAL" ]; then
    # A direct side-by-side entity's stable path is a symlink. Archive the
    # actual version directory, then retain the symlink as a recovery breadcrumb
    # rather than moving only a now-broken link and leaving user data in place.
    mv "$_BM_PT_REAL" "$_BM_ARCHIVE/plain" || exit 1
    mv "$_BM_PT" "$_BM_ARCHIVE/stable-link" || exit 1
else
    mv "$_BM_PT" "$_BM_ARCHIVE/plain" || exit 1
fi

lifecycle_log "info" "backend_migrate" "backend_migrate_ok" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"target\":\"layering\",\"layer\":\"$_BM_NEW_LAYER\",\"archive\":\"$_BM_ARCHIVE\"}" 2>/dev/null || true
_backend_migration_mark_changed
exit 0
)

# agent_staging_upper <agent_id> <persist_path>
# Echo the staging writable layer's path — the thing op_bake is asked to bake
# once an install into the staging mount has succeeded.
agent_staging_upper() {
    local id="${1:-}" persist="${2:-}"
    declare -f _entity_paths >/dev/null 2>&1 || source "$_SO_DIR/common.sh"
    declare -f agent_staging_entity_key >/dev/null 2>&1 || source "$_SO_DIR/resolve_paths.sh"
    _entity_paths agent "$(agent_staging_entity_key "$id")" "$persist"
    printf '%s\n' "$UPPER_DIR"
}

# ---- op_bake  (from commit_stack.sh) ----------------------------
op_bake() (
set -euo pipefail
# AICliAgents: Persistence Bake (ZRAM -> SquashFS)
# Usage: op_bake <type: agent|home> <id> <persistence_path> [upper_override]
#
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15): the optional 4th argument
# names the writable layer to bake, instead of the one the entity is currently
# serving from. It exists for exactly one caller — capturing what an install
# wrote into its own staging layer (op_stage) — and it changes two things: the
# layer that gets baked, and the fact that the post-bake trim is skipped, because
# the caller, not this function, owns that layer's lifetime.

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
_UPPER_OVERRIDE="${4:-}"

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$_SO_DIR/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Skips on exit 2 (which
# commit_stack.sh uses for "baked but mount busy — ZRAM flush deferred").
install_failure_trap "$TYPE" "$ID" "commit_stack"

# Source canonical path resolver and lifecycle log writer (Phase 1)
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

# F6 (WP#1331): the SINGLE manifest writer (replaces the inline php -r addLayer copy).
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || {
    error "atomic_write_layer.sh missing — cannot bake safely"
    exit 1
}

# #342: derive UPPER_DIR from persistence fstype (vfat→ZRAM, else→disk direct).
# Must match the logic in mount_stack.sh so we bake from the correct upper layer.
_entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE  # Phase 3: the LIVE generation's layer, not the id-keyed one
if [ -n "$_UPPER_OVERRIDE" ]; then
    UPPER_DIR="$_UPPER_OVERRIDE"
    # An overridden layer belongs to no generation, so the post-bake trim must
    # never run against it: ENTITY_GENERATION is what that decision reads.
    ENTITY_GENERATION=""
fi

# Bug #716: per-entity bake flock — serialise concurrent bakes of the same entity.
# All bake paths (InstallerService::commitChanges, supervisor _op_bake,
# event/stopping direct bake, installer/cleanup.sh pre-upgrade bake) funnel
# through this script, so the lock here covers every caller.
# Sanitise $ID to keep the lock filename shell-safe (alphanumeric + hyphen).
_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
_BAKE_LOCK="/var/run/aicli-bake-${TYPE}-${_LOCK_ID}.lock"
_BUSY_SNAPSHOT_MARKER="/tmp/unraid-aicliagents/.bake_busy_snapshot_${TYPE}_${_LOCK_ID}"
_HOME_BUSY_AT_BAKE_START=0
exec 9>"$_BAKE_LOCK"
# 2026-09-24 (SIDE_BY_SIDE_AGENT_INSTALLS.md "the version that runs is the
# version recorded"): a STAGED bake (an override layer) must never take the
# "skip" below. The bake in progress reads the LIVE layer, not the staging
# layer, and the installer removes the staging layer right after this returns,
# so a skip threw the new version away and still reported success (.4: a goose
# downgrade to 1.51.0 recorded 1.51.0 and kept running 1.52.0). Wait for the
# other bake, with a limit; if it does not end, fail, so the install fails and
# the version in service stays as it is.
if [ -n "$_UPPER_OVERRIDE" ] && ! flock -n 9; then
    _staged_wait="${AICLI_STAGED_BAKE_LOCK_WAIT_S:-600}"
    case "$_staged_wait" in ''|*[!0-9]*) _staged_wait=600 ;; esac
    lifecycle_log "info" "commit_stack" "staged_bake_lock_wait" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"limit_s\":$_staged_wait}" 2>/dev/null || true
    if ! flock -w "$_staged_wait" 9; then
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date)] [ERR!] [COMMIT] staged bake for $TYPE/$ID: another bake still holds the lock after ${_staged_wait}s — the staged install is NOT captured"
        lifecycle_log "error" "commit_stack" "staged_bake_lock_timeout" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"limit_s\":$_staged_wait}" 2>/dev/null || true
        exit 1
    fi
fi
# 2026-09-29 (#357, HOME_STORAGE_LIFECYCLE.md "a save that meets a non-bake
# lock holder"): the lock is NOT only held by a bake. The supervisor's reconcile
# check, a consolidate swap, a rebase, a restore and a wipe take the same lock.
# The old code skipped for ANY holder and exited 0 with "the in-progress bake
# will capture the changes". With a reconcile holder no bake ran, nothing was
# saved, and the caller (Persist, a backup, an install) was told "saved".
# Now a lock holder writes an owner record next to the lock
# ("op=<name> pid=<pid> since=<epoch>"). A bake skips ONLY when the record names
# a LIVE normal bake: that bake reads the same writable layer and saves it. For
# every other holder the bake waits for the lock (a non-blocking poll with a
# deadline, never a blocking flock) and then bakes. When the deadline passes,
# the bake saves NOTHING and says so: exit 2, defer reason bake_lock_held,
# lifecycle event bake_deferred_locked. Callers treat that result as "not
# saved" (AICLI_NOT_SAVED_DEFER_REASONS in common.sh).
_BAKE_LOCK_OWNER="${_BAKE_LOCK}.owner"
# _bake_lock_owner_field <key> — one field of the owner record, or empty.
_bake_lock_owner_field() {
    local _rec
    _rec="$(head -c 256 "$_BAKE_LOCK_OWNER" 2>/dev/null)" || return 0
    printf '%s\n' "$_rec" | tr ' ' '\n' | sed -n "s/^$1=//p" | head -1
}
# _proc_start_ticks <pid> — the start time of a process (field 22 of
# /proc/<pid>/stat), or empty. A pid can be reused; the pid plus this value
# cannot. R-080: the name of the process is in brackets and may hold spaces, so
# cut everything up to the last ") " first.
_proc_start_ticks() {
    local _stat
    _stat="$(cat "/proc/$1/stat" 2>/dev/null)" || return 0
    _stat="${_stat##*) }"
    set -- $_stat
    printf '%s' "${20:-}"
}
# _bake_lock_holder_is_bake — 0 when the owner record names a live normal bake.
# A staged bake (op=bake_staged) reads the staging layer, not the live one, so
# it does not save the live changes and does not count. R-080: a record of a
# bake that ended can name a pid that a new process now uses. When the record
# has a start time, it must match the process; a mismatch is a dead bake.
_bake_lock_holder_is_bake() {
    local _op _pid _start
    _op="$(_bake_lock_owner_field op)"
    [ "$_op" = "bake" ] || return 1
    _pid="$(_bake_lock_owner_field pid)"
    case "$_pid" in ''|*[!0-9]*) return 1 ;; esac
    [ "$_pid" != "${BASHPID:-$$}" ] || return 1
    kill -0 "$_pid" 2>/dev/null || return 1
    _start="$(_bake_lock_owner_field start)"
    if [ -n "$_start" ]; then
        [ "$(_proc_start_ticks "$_pid")" = "$_start" ] || return 1
    fi
    return 0
}
# _bake_owner_release — remove the owner record when it is this process's own
# (R-080). The kernel frees the lock on exit; the record must go with it.
_bake_owner_release() {
    [ "$(_bake_lock_owner_field pid)" = "${BASHPID:-$$}" ] || return 0
    rm -f "$_BAKE_LOCK_OWNER" 2>/dev/null || true
}
# _bake_lock_holder_desc — "<op> (pid <pid>)" for the log, or "unknown".
_bake_lock_holder_desc() {
    local _op _pid
    _op="$(_bake_lock_owner_field op)"
    _pid="$(_bake_lock_owner_field pid)"
    case "$_pid" in ''|*[!0-9]*) _pid="" ;; esac
    if [ -n "$_op" ] && [ -n "$_pid" ] && kill -0 "$_pid" 2>/dev/null; then
        printf '%s (pid %s)' "$_op" "$_pid"
    else
        printf 'unknown'
    fi
}
# _bake_skip_for_running_bake — the one correct skip: another bake of the SAME
# writable layer is running, and that bake saves these changes.
_bake_skip_for_running_bake() {
    local _holder
    _holder="$(_bake_lock_holder_desc)"
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date)] [INFO] [COMMIT] bake for $TYPE/$ID skipped: another bake of the same writable layer is running ($_holder) and saves these changes"
    lifecycle_log "info" "commit_stack" "bake_skipped_concurrent" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"holder\":\"bake\"}" 2>/dev/null || true
    exit 0
}
if ! flock -n 9; then
    _bake_lock_holder_is_bake && _bake_skip_for_running_bake
    _lock_wait_s="${AICLI_BAKE_LOCK_WAIT_S:-30}"
    case "$_lock_wait_s" in ''|*[!0-9]*) _lock_wait_s=30 ;; esac
    [ "$_lock_wait_s" -le 120 ] || _lock_wait_s=120
    _lock_holder="$(_bake_lock_holder_desc)"
    # R8: when the owner record names no live process, name the real holder.
    if [ "$_lock_holder" = "unknown" ] && declare -f lock_holder_describe >/dev/null 2>&1; then
        _lock_holder="$(lock_holder_describe "$_BAKE_LOCK" 2>/dev/null)" || _lock_holder="unknown"
        [ -n "$_lock_holder" ] || _lock_holder="unknown"
    fi
    lifecycle_log "info" "commit_stack" "bake_lock_wait" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"holder\":\"$_lock_holder\",\"limit_s\":$_lock_wait_s}" 2>/dev/null || true
    _lock_start=$SECONDS
    _lock_deadline=$((SECONDS + _lock_wait_s))
    _lock_got=0
    while :; do
        if flock -n 9; then _lock_got=1; break; fi
        # The holder can change while we wait: a bake that takes the lock after
        # a reconcile saves the same layer, so the skip is correct again.
        if _bake_lock_holder_is_bake; then _bake_skip_for_running_bake; fi
        [ "$SECONDS" -lt "$_lock_deadline" ] || break
        sleep 0.25
    done
    if [ "$_lock_got" -ne 1 ]; then
        _lock_holder="$(_bake_lock_holder_desc)"
        if [ "$_lock_holder" = "unknown" ] && declare -f lock_holder_describe >/dev/null 2>&1; then
            _lock_holder="$(lock_holder_describe "$_BAKE_LOCK" 2>/dev/null)" || _lock_holder="unknown"
            [ -n "$_lock_holder" ] || _lock_holder="unknown"
        fi
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date)] [WARN] [COMMIT] bake for $TYPE/$ID NOT saved: another storage operation ($_lock_holder) still holds the lock after ${_lock_wait_s}s. The changes stay in the writable layer; the next save captures them."
        lifecycle_log "warn" "commit_stack" "bake_deferred_locked" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"holder\":\"$_lock_holder\",\"waited_s\":$((SECONDS - _lock_start))}" 2>/dev/null || true
        if declare -f write_defer_reason >/dev/null 2>&1; then
            write_defer_reason "$TYPE" "$ID" "bake_lock_held"
        fi
        exit 2
    fi
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date)] [INFO] [COMMIT] bake for $TYPE/$ID waited $((SECONDS - _lock_start))s for the storage lock ($_lock_holder); baking now"
fi
# Record this bake as the lock holder, so a concurrent bake of the same layer
# can skip and every other caller can see who holds the lock. Atomic (tmp + mv).
if [ -n "$_UPPER_OVERRIDE" ]; then _bake_owner_op="bake_staged"; else _bake_owner_op="bake"; fi
# Read the pid here, not inside $(...): there BASHPID is the pid of the
# command-substitution subshell, so the record held the start time of a
# process that had already ended, and a second bake never saw this bake as
# live (#691).
_bake_owner_pid="${BASHPID:-$$}"
if ! { printf 'op=%s pid=%s since=%s start=%s\n' "$_bake_owner_op" "$_bake_owner_pid" "$(date +%s)" "$(_proc_start_ticks "$_bake_owner_pid")" > "${_BAKE_LOCK_OWNER}.$$" \
        && mv -f "${_BAKE_LOCK_OWNER}.$$" "$_BAKE_LOCK_OWNER"; } 2>/dev/null; then
    rm -f "${_BAKE_LOCK_OWNER}.$$" 2>/dev/null || true
fi
# R-080: remove the record on every exit, so a later pid reuse cannot make the
# record look like a live bake.
if declare -f aicli_on_exit >/dev/null 2>&1; then aicli_on_exit _bake_owner_release; fi
# Lock is held on fd 9 for the remainder of the script (bake + remount +
# post-bake consolidate-threshold check).  The kernel releases it on exit
# (clean or crash) so there is no risk of a stale-lock deadlock.

# WP #1078: clear any stale defer-reason marker from a prior run so the
# reason read by TaskService.php / StorageMountService.php is THIS bake's
# truth, never a prior cycle's. Cheap rm at start; each exit-2 path writes
# the current cause via write_defer_reason; exit-0 leaves no marker.
rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_LOCK_ID}" 2>/dev/null || true

# WP #1277 (bake-confirmed reclaim): the layer writer records the files it
# actually captured into this manifest; we map them onto UPPER_DIR below and
# pass them to selective_upper_cleanup so the post-bake reclaim only wipes
# proven-baked files. Stable per-entity path (overwritten each bake); cleared
# now so a prior run's list can never be mistaken for this bake's truth.
_BAKE_MANIFEST="/tmp/unraid-aicliagents/.bake_manifest_${TYPE}_${_LOCK_ID}"
rm -f "$_BAKE_MANIFEST" "${_BAKE_MANIFEST}.abs" 2>/dev/null || true

log() {
    local msg="[$(get_ts)] [INFO] [COMMIT] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [COMMIT] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

lifecycle_log "info" "commit_stack" "bash_bake_start" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true

# 2026-09-30 (#374, HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the SQLite stage
# folder"): the SQLite stage folder of this bake is on the file system of the
# writable layer (sqlite_stage_root), never on the RAM root file system, and it
# is removed on EVERY exit: 0, 1, the exit-2 defers, a set -e stop, and a
# TERM/INT/HUP (the handlers turn the signal into an exit, so the EXIT handler
# of install_failure_trap runs the hook). A SIGKILL cannot run a handler: the
# sweep below removes what a killed bake left, when its owner pid has ended.
SQLITE_STAGE=""
_SQLITE_STAGE_ROOT="$(sqlite_stage_root "$UPPER_DIR")"
_bake_scratch_cleanup() {
    if [ -n "${SQLITE_STAGE:-}" ]; then rm -rf -- "$SQLITE_STAGE" 2>/dev/null || true; fi
    return 0
}
aicli_on_exit _bake_scratch_cleanup
trap 'exit 143' TERM
trap 'exit 130' INT
trap 'exit 129' HUP
_SWEPT="$(sqlite_stage_sweep_orphans "$_SQLITE_STAGE_ROOT" "$AICLI_SQLITE_STAGE_LEGACY_ROOT"; bake_merge_sweep_orphans "$UPPER_DIR")"
if [ -n "$_SWEPT" ]; then
    _SWEPT_N="$(printf '%s\n' "$_SWEPT" | awk 'NF{c++}END{print c+0}')"
    log "Removed $_SWEPT_N scratch folder(s) of ended bakes: $(printf '%s' "$_SWEPT" | tr '\n' ' ')"
    lifecycle_log "warn" "commit_stack" "stage_orphans_removed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"count\":$_SWEPT_N}" 2>/dev/null || true
fi

# Busy-bake cooldown gate (home only). When a live session keeps the home busy,
# the post-bake reclaim defers (home_mount_in_use) so the ZRAM upper is never
# trimmed. Without this gate, every bake trigger (StorageMountService config
# reads, scheduled ticks, etc.) would re-bake the WHOLE untrimmed upper while a
# session is open -> redundant Flash writes + delta-layer bloat. So while busy we
# persist at most once per cooldown (crash-safety) and skip redundant bakes in
# between. Idle bakes are NEVER gated -- they reclaim and trim, clearing the
# marker. Override via AICLI_BUSY_BAKE_COOLDOWN_SEC (tests / tuning).
if [ "$TYPE" = "home" ] && home_mount_in_use "$MNT_POINT"; then
    _HOME_BUSY_AT_BAKE_START=1
    if ! _prepare_busy_snapshot_roll "$TYPE" "$ID" "$_BUSY_SNAPSHOT_MARKER"; then
        log "Could not prepare rolling busy snapshot marker; this bake will retain every layer for safety"
    fi
    _BUSY_COOLDOWN_SEC="${AICLI_BUSY_BAKE_COOLDOWN_SEC:-1800}"
    _COOLDOWN_MARKER="/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}"
    _last_busy_bake=0
    [ -f "$_COOLDOWN_MARKER" ] && _last_busy_bake=$(cat "$_COOLDOWN_MARKER" 2>/dev/null || echo 0)
    case "$_last_busy_bake" in ''|*[!0-9]*) _last_busy_bake=0 ;; esac
    _now_cd=$(date +%s)
    if [ $(( _now_cd - _last_busy_bake )) -lt "$_BUSY_COOLDOWN_SEC" ]; then
        log "Home busy and last persist within ${_BUSY_COOLDOWN_SEC}s cooldown — skipping redundant bake (data already persisted; reclaim deferred to idle)."
        _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_busy_cooldown" "busy_cooldown" "{\"type\":\"$TYPE\",\"id\":\"$ID\"}"
    fi
    # Forgejo #445: an AUTOMATIC bake of a busy home whose writable layer lives
    # on durable DISK writes nothing. The reclaim would defer anyway (the upper
    # is not emptied while a session holds the home), the data is already
    # durable across a reboot, and the layer would only copy the whole upper
    # onto the same device every cooldown. A person's Persist and the other
    # explicit callers (no AICLI_BAKE_REASON) still bake.
    if _busy_bake_pointless_on_disk "${ENTITY_UPPER_MODE:-}" "${AICLI_BAKE_REASON:-}"; then
        log "Home busy and its writable layer is on disk ($UPPER_DIR), so the data is already durable — this automatic save writes no layer. A compact layer is written when the home is idle."
        date +%s > "$_COOLDOWN_MARKER" 2>/dev/null || true
        _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_busy_disk_upper" "upper_on_disk" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"upper\":\"$UPPER_DIR\",\"bake_reason\":\"${AICLI_BAKE_REASON:-}\"}"
    fi
fi

# 1. Keep regenerable caches OUT of the layer (H: the emptiness check MUST come
#    after this step, so an upper that holds only caches short-circuits the bake).
#
# Forgejo #298 (2026-09-23): this step used to DELETE the cache contents from
# the LIVE upper before every bake, also while a session ran. A running or
# starting OpenCode lost .cache/opencode, and Bun then failed with ENOENT on
# `mkdir .cache/opencode/bin`. Now mksquashfs EXCLUDES the caches (-wildcards
# -ef, see _bake_prune_patterns), so the layer stays small (D-317) and the
# live files survive. The excluded paths are: .npm, .cache and tmp contents
# (contents only, never the directory), plus for a home .bun/install,
# .claude/cache, .claude/shell-snapshots and .claude/telemetry. WP #931: never
# .gemini/tmp (gemini-cli chat logs). HARD CONSTRAINT: never
# snap_*/migrated_legacy_data/*backup*/SAFE_BACKUP (recovery artefacts).
# The upper copy is removed only after a successful bake, and only while the
# mount is idle (_bake_prune_upper, after the reclaim below).
log "Pruning caches before bake (excluded from the layer; live files kept)..."
_BAKE_EXCLUDE_FILE="/tmp/unraid-aicliagents/.bake_excludes_${TYPE}_${_LOCK_ID}"
if _bake_prune_patterns "$TYPE" > "$_BAKE_EXCLUDE_FILE" 2>/dev/null; then
    MKSQUASHFS_ARGS="$(_bake_args_with_excludes "${MKSQUASHFS_ARGS:-${_AWL_DEFAULT_ARGS:--comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend}}" "$_BAKE_EXCLUDE_FILE")"
    export MKSQUASHFS_ARGS
else
    error "Could not write the cache exclude list $_BAKE_EXCLUDE_FILE — refusing to bake caches into Flash."
    exit 1
fi

# H: Skip bake entirely if the upper is empty (or holds only excluded caches).
# This prevents writing a zero-content delta to Flash.
# #372: UPPER_DIR is the LIVE upper (_entity_paths_live reads the mount table),
# never the empty upper of a mode the policy gives only for a new mount. The
# event names the upper it judged, so "empty" can be checked afterwards.
if [ ! -d "$UPPER_DIR" ] || ! _bake_upper_has_content "$TYPE" "$UPPER_DIR"; then
    log "No changes to commit for $TYPE $ID (upper empty after prune: $UPPER_DIR)"
    lifecycle_log "info" "commit_stack" "bash_bake_skipped_empty" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"upper\":\"$UPPER_DIR\",\"upper_source\":\"${ENTITY_UPPER_SOURCE:-policy}\"}" 2>/dev/null || true
    # #567: tell the Activity tray that this save found nothing to write. The
    # tray reads and removes this marker when the job ends (SupervisorService
    # ::bakeNoopStep) and says "Nothing to save" instead of a bare "Done".
    printf '%s\n' "$(date +%s)" > "/tmp/unraid-aicliagents/.bake_noop_${TYPE}_${ID//[^a-zA-Z0-9_-]/_}" 2>/dev/null || true
    # #372: a home still on the upper of the old mode moves to the policy
    # upper once that upper is empty and nothing uses the home.
    if [ "$TYPE" = "home" ] && [ -z "$_UPPER_OVERRIDE" ] \
       && [ "${ENTITY_UPPER_MODE:-}" != "${ENTITY_POLICY_UPPER_MODE:-${ENTITY_UPPER_MODE:-}}" ]; then
        _bake_prune_upper_if_idle "$TYPE" "$UPPER_DIR" "$MNT_POINT" || true
        _home_upper_converge "$ID" "$PERSIST_PATH" "$UPPER_DIR" || true
    fi
    exit 0
fi

# Validate persistence path before writing
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — bake refused"; exit 1; }

# Check disk space (need at least 100MB free for a delta)
check_disk_space "$PERSIST_PATH/.diskcheck" 100 || { error "Insufficient disk space on $PERSIST_PATH"; exit 1; }

# S-09 (#1352): FAT32 per-file cap preflight. On a vfat persist target (USB boot)
# a single file caps at 4 GiB — if the projected delta (du of the upper) is within
# 5% of the cap, mksquashfs would fail mid-write with a confusing error. Refuse
# UP FRONT: exit 4 (precondition failed), marker fat32_size_cap, dynamix notify.
# fstype comes from findmnt inside the check — never from a path prefix.
if ! _fat32_cap_check "$PERSIST_PATH" "$UPPER_DIR"; then
    error "Projected delta size (${_FAT32_PROJECTED_BYTES:-0} bytes) is within 5% of the FAT32 4 GiB per-file cap — refusing bake (precondition)."
    _fat32_cap_refuse "$TYPE" "$ID" "commit_stack"
    exit 4
fi

# Record a marker timestamp BEFORE baking. Any writes to the upper dir after this
# point will not be in the delta and must NOT be flushed.
MARKER="/tmp/unraid-aicliagents/.commit_marker_${TYPE}_${ID}"
touch "$MARKER"
# M2 fix (v2026.05.18.08): 50ms gap separates the marker mtime from any
# write that could land at exactly the same tmpfs nanosecond timestamp.
# selective_upper_cleanup uses `find ! -newer $marker` which is mtime <= marker
# (inclusive). A write happening in the same nanosecond as `touch` would
# otherwise be admitted to the wipe set despite not necessarily being in the
# bake. The cost (50ms) is imperceptible relative to a bake (seconds).
sleep 0.05

# WP #935: detect SQLite DBs in UPPER and back them up via Online Backup API
# BEFORE the bake. SQLite's .backup is safe against concurrent writers
# (page-version-counter detection); the .backup output replaces the live DB
# via the no-mount merge for the bake (see WP #1078/#236). WAL/SHM/journal sidecars
# are excluded — SQLite reconstitutes them from the .db on next open.
#
# WP #1078 (2026-05-24): replaces the prior two-pass `wide-bake + mksquashfs
# -append <sqlite-stage>` protocol. mksquashfs's append mode does NOT merge
# new content into existing directories — when the source dir (sqlite stage)
# has top-level entries that already exist in the target squashfs, mksquashfs
# RENAMES the new ones with _N suffix (.copilot/ → .copilot_1/). The SQLite
# backups landed at unreachable paths, silently lost on next mount. The
# byte-growth defence-in-depth check (8b4c397) caught only the subset
# where the appended content was small enough that 4 KB block alignment
# masked the growth — when the append "succeeded" with bigger content, the
# DBs were stranded at .copilot_1/session-store.db etc., usable by nothing.
# New path: hardlink merge for the live upper → single bake. The consolidate
# path below uses a no-mount mksquashfs pseudo-file merge because its source is
# the live merged view rather than a plain filesystem directory.
SQLITE_DBS=$(detect_sqlite_dbs "$UPPER_DIR" 2>/dev/null)
# #388: the mounted view of a live upper (the agents open files through it).
# sqlite_dbs_needing_stage also checks it for open handles.
_SQLITE_READ_ROOT=""
if [ -z "$_UPPER_OVERRIDE" ] && [ "${ENTITY_UPPER_SOURCE:-}" = "live" ] && [ -d "$MNT_POINT" ]; then
    _SQLITE_READ_ROOT="$MNT_POINT"
fi
# #388: stage ONLY the databases that need a copy (open, or a -wal with
# content). The others are consistent: the hardlink in the merge tree is used
# as it is, and the space check counts only the staged ones.
_SQLITE_ALL_COUNT=$(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}')
if [ "$_SQLITE_ALL_COUNT" -gt 0 ]; then
    # shellcheck disable=SC2086 — intentional word-splitting on the path list
    SQLITE_DBS=$(AICLI_SQLITE_READ_ROOT="$_SQLITE_READ_ROOT" sqlite_dbs_needing_stage "$UPPER_DIR" $SQLITE_DBS 2>/dev/null)
    log "SQLite: $_SQLITE_ALL_COUNT database(s) in upper, $(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}') need a staged copy"
fi
# Count non-empty lines. Previous `echo | grep -c -v` produced "0\n0" when
# SQLITE_DBS was empty (grep -c outputs "0" AND returns exit 1, triggering the
# `|| echo 0`), breaking the integer comparison below with a benign-but-noisy
# `[: integer expected` stderr warning. awk handles empty input cleanly.
SQLITE_DB_COUNT=$(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}')
SQLITE_STAGE=""

if [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Detected $SQLITE_DB_COUNT SQLite DB(s) in upper — backing up via Online Backup API"
    # #374: stage nothing when the file system has no room for the copies. The
    # bake saves nothing (exit 2, no_space = not saved) and says why.
    # shellcheck disable=SC2086 — intentional word-splitting on the path list
    if ! sqlite_stage_fits "$_SQLITE_STAGE_ROOT" $SQLITE_DBS; then
        rm -f "$MARKER"
        error "Not enough room to stage $SQLITE_DB_COUNT SQLite backup(s) in $_SQLITE_STAGE_ROOT: need about ${STAGE_NEED_MB} MB (${STAGE_DB_MB} MB of databases plus margin), ${STAGE_FREE_MB} MB free. Nothing was saved; the changes stay in the writable layer."
        # #388: the figures for the supervisor's notification ("need free").
        printf '%s %s\n' "${STAGE_NEED_MB:-0}" "${STAGE_FREE_MB:-unknown}" \
            > "/tmp/unraid-aicliagents/.bake_no_space_${TYPE}_${ID//[^a-zA-Z0-9_-]/_}" 2>/dev/null || true
        _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_deferred" "no_space" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_stage_no_space\",\"db_count\":$SQLITE_DB_COUNT,\"need_mb\":$STAGE_NEED_MB,\"free_mb\":\"$STAGE_FREE_MB\",\"stage_root\":\"$_SQLITE_STAGE_ROOT\",\"on_ram\":$STAGE_ON_RAM}" "warn"
    fi
    SQLITE_STAGE="$(sqlite_stage_path "$_SQLITE_STAGE_ROOT" "" "$TYPE" "$ID" "${BASHPID:-$$}")"
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    if ! mkdir -p "$SQLITE_STAGE" 2>/dev/null; then
        rm -f "$MARKER"
        error "Could not create the SQLite stage folder $SQLITE_STAGE — failing bake"
        lifecycle_log "error" "commit_stack" "bash_bake_failed" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_stage_mkdir_failed\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
        exit 1
    fi

    # #374: never a bare call. Under set -e a non-zero return stopped op_bake
    # HERE, before the cleanup and the defer below: the stage folder stayed in
    # RAM and the log showed no reason (the .4 incident, 2026-09-30).
    # #374: a MOUNTED entity whose live upper is the one baked here: read each
    # database through the mounted view (AICLI_SQLITE_READ_ROOT), the way the
    # agents open it. The upper path shows overlay whiteouts for deleted
    # -wal/-shm files, and sqlite3 cannot open a database beside them.
    # (_SQLITE_READ_ROOT is set above, before the stage filter.)
    _sba_rc=0
    # shellcheck disable=SC2086 — intentional word-splitting on the path list
    AICLI_SQLITE_READ_ROOT="$_SQLITE_READ_ROOT" sqlite_backup_all "$UPPER_DIR" "$SQLITE_STAGE" $SQLITE_DBS || _sba_rc=$?
    if [ "$_sba_rc" -ne 0 ]; then
        log "SQLite backup of ${SQLITE_BACKUP_FAILED_DB:-a database} failed (rc=$_sba_rc): ${SQLITE_BACKUP_ERROR:-no message}"
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$MARKER"
        # M3 fix (v2026.05.18.08): distinguish hard error (return 1 — staging
        # mkdir failed, sqlite3 missing, permission denied) from defer-eligible
        # (return 2 — DB locked, backup timeout). The previous code conflated
        # both as exit 2, leaving a hard error to defer indefinitely while
        # UPPER accumulated unbaked data — a power cycle during the stuck-defer
        # window would lose it all.
        if [ "$_sba_rc" -eq 1 ]; then
            error "SQLite backup hard error (mkdir / sqlite3 / permission) — failing bake"
            lifecycle_log "error" "commit_stack" "bash_bake_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_hard_error\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
            exit 1
        fi
        log "SQLite backup deferred (DB locked or backup timeout) — exiting 2 to retry"
        # WP #1078: distinguish defer reasons for the UI (see TaskService.php). The
        # lifecycle payload's reason ('sqlite_backup_failed') is the diagnostic; the
        # marker reason ('sqlite_backup_deferred') is the UI key — keep both.
        _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_deferred" "sqlite_backup_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_failed\",\"db_count\":$SQLITE_DB_COUNT}"
    fi
fi

# 2. Atomic bake. Two paths:
#   (a) SQLite DBs detected → hardlink-merge bake (single pass; sqlite_stage
#       shadows the live DBs in UPPER_DIR; WAL/SHM/journal excluded).
#   (b) No SQLite DBs → direct bake of UPPER_DIR.
# Apps continue writing freely; the bake reads at file level via the merged FS.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"

# WP #1277: ask the layer writer (both the direct and hardlink-merge paths run
# atomic_write_layer as a sourced function in THIS process) to record the files
# it captures. A plain shell var suffices — the command-substitution subshells
# inherit it without an export, and it does not leak into mksquashfs's env.
AICLI_BAKE_MANIFEST_OUT="$_BAKE_MANIFEST"

NEW_BASENAME=""
if [ "$SQLITE_DB_COUNT" -gt 0 ] && [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ]; then
    log "Baking changes to $PERSIST_PATH/ (atomic delta, hardlink-merge with $SQLITE_DB_COUNT SQLite backup(s))..."
    # GitHub #9: UPPER_DIR is the live home overlay's OWN upperdir throughout
    # this whole bake (apps keep writing to it) — the old overlay merge would
    # mount a SECOND overlay using it as that mount's lowerdir, which is
    # exactly the kernel-flagged "lowerdir is in-use as upperdir/workdir of
    # another mount" hazard. bake_via_hardlink_merge does the same merge with
    # no mount at all (see its doc comment in common.sh). op_consolidate's own
    # call below is separate: it sources the mounted merged VIEW and uses the
    # no-mount pseudo-file merge instead.
    # shellcheck disable=SC2086 — intentional word-splitting on the DB path list
    if ! NEW_BASENAME=$(bake_via_hardlink_merge "$TYPE" "$ID" "$PERSIST_PATH" "$UPPER_DIR" "$SQLITE_STAGE" "delta" $SQLITE_DBS); then
        error "Hardlink-merge bake failed."
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$MARKER"
        lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\",\"reason\":\"hardlink_merge_bake_failed\"}" 2>/dev/null || true
        exit 1
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "commit_stack" "bash_bake_sqlite_merged" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
else
    # #338 (SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent layer
    # retention"): a STAGED agent install bakes the merged staging view — the
    # complete new package over the same read-only layers — as a fresh BASE
    # (kind consolidated), not the staging writable layer as a delta. The new
    # generation then stacks that one layer, and the older layers stop being
    # part of any new view, so the retention sweep can release them. Every
    # other bake (a normal persist, a home, no staging view) is unchanged.
    _BAKE_SRC="$UPPER_DIR"; _BAKE_KIND="delta"
    if [ -n "$_UPPER_OVERRIDE" ] && [ "$TYPE" = "agent" ] && declare -f agent_staging_mount >/dev/null 2>&1; then
        _STAGE_VIEW="$(agent_staging_mount "$ID" 2>/dev/null || true)"
        _STAGE_VIEW_MOUNTED=0
        [ -n "$_STAGE_VIEW" ] && mountpoint -q "$_STAGE_VIEW" 2>/dev/null && _STAGE_VIEW_MOUNTED=1
        IFS=$'\t' read -r _BAKE_SRC _BAKE_KIND < <(_bake_source_for "$TYPE" "$UPPER_DIR" "$_UPPER_OVERRIDE" "$_STAGE_VIEW" "$_STAGE_VIEW_MOUNTED" "$SQLITE_DB_COUNT")
    fi
    if [ "$_BAKE_KIND" = "consolidated" ]; then
        log "Baking the staged install as a fresh base layer from $_BAKE_SRC (complete package, no delta on older layers)..."
    else
        log "Baking changes to $PERSIST_PATH/ (atomic delta, no SQLite content)..."
    fi
    # #338: an agent layer is about one package — the largest layer of the
    # stack in service estimates it. Never START a bake whose result cannot
    # fit: the install fails, logged, and the version in service stays.
    if [ "$TYPE" = "agent" ]; then
        mapfile -t _STACK_PATHS < <(_layer_stack_sorted "$PERSIST_PATH" "$TYPE" "$ID")
        _EST_BYTES="$(_layer_largest_bytes "${_STACK_PATHS[@]}")"
        if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
            error "Not enough room on $PERSIST_PATH for the new $_BAKE_KIND layer of $ID: it needs about ${_FIT_NEED_MB} MB (estimate plus margin), ${_FIT_FREE_MB} MB free — bake not started."
            rm -f "$MARKER"
            lifecycle_log "error" "commit_stack" "bash_bake_no_space" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"kind\":\"$_BAKE_KIND\",\"need_mb\":$_FIT_NEED_MB,\"free_mb\":\"$_FIT_FREE_MB\",\"estimate_bytes\":$_EST_BYTES}" 2>/dev/null || true
            write_defer_reason "$TYPE" "$ID" "no_space"
            exit 1
        fi
    else
        # R-021 (#599): a home delta is about the size of what the writable layer
        # holds (worst case: it does not compress). The fixed 100 MB check above
        # passed a 1.5 GB upper on a boot disk with 300 MB free; mksquashfs then
        # filled /boot for minutes and failed, and the automatic bake repeated it
        # at every cool-down. Defer with no_space instead, before anything is written.
        _EST_BYTES="$(_dir_bytes "$_BAKE_SRC")"
        # F14 (#599): the worst case (no compression) is only the first test. When
        # it fails, test again with the compression ratio of the newest layer, so a
        # save is not refused for a layer that will fit while the data sits in RAM.
        if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
            _EST_WORST="$_EST_BYTES"
            _EST_BYTES="$(_layer_realistic_estimate "$PERSIST_PATH" "$TYPE" "$ID" "$_EST_WORST")"
            if [ "$_EST_BYTES" -lt "$_EST_WORST" ] && _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
                log "The worst-case size of the new layer (${_EST_WORST} bytes) does not fit, but the compression of the last layer puts it at about ${_EST_BYTES} bytes; it fits. Baking."
            fi
        fi
        if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
            error "Not enough boot disk space to save $ID: the new $_BAKE_KIND layer on $PERSIST_PATH needs about ${_FIT_NEED_MB} MB (estimate plus margin), ${_FIT_FREE_MB} MB free — bake not started. The changes stay in the writable layer."
            # The figures for the supervisor's notification and tray entry (#388).
            write_no_space_note "$TYPE" "$ID" "${_FIT_NEED_MB:-0}" "${_FIT_FREE_MB:-unknown}"
            rm -f "$MARKER"
            _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_no_space" "no_space" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"kind\":\"$_BAKE_KIND\",\"need_mb\":$_FIT_NEED_MB,\"free_mb\":\"$_FIT_FREE_MB\",\"estimate_bytes\":$_EST_BYTES}" "warn"
        fi
    fi
    if ! NEW_BASENAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$_BAKE_SRC" "$_BAKE_KIND"); then
        error "Atomic bake failed."
        rm -f "$MARKER"
        lifecycle_log "error" "commit_stack" "bash_bake_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
        exit 1
    fi
    # A base that lists no file while the staged install wrote a non-empty file
    # cannot hold the install: remove it and fail the install, so the version
    # in service stays (the #296 guard, applied to the new base).
    if [ "$_BAKE_KIND" = "consolidated" ] \
       && _layer_near_empty "$(stat -c '%s' "$PERSIST_PATH/$NEW_BASENAME" 2>/dev/null || echo 0)" "$UPPER_DIR" "$PERSIST_PATH/$NEW_BASENAME"; then
        error "#338: the fresh base $NEW_BASENAME lists no file, but the staged install wrote files — removing it; the install is not captured."
        rm -f "$PERSIST_PATH/$NEW_BASENAME" "$MARKER" 2>/dev/null || true
        lifecycle_log "error" "commit_stack" "agent_fresh_base_refused" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\",\"reason\":\"near_empty\"}" 2>/dev/null || true
        exit 1
    fi
    [ "$_BAKE_KIND" = "consolidated" ] && lifecycle_log "info" "commit_stack" "agent_fresh_base_written" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\"}" 2>/dev/null || true
fi

# NEW_SQSH is the final path of the just-written layer
NEW_SQSH="$PERSIST_PATH/$NEW_BASENAME"
log "Bake complete: $NEW_BASENAME"

# Epic #1310 Step 7: record the manifest entry HERE — in bash, while the fd9 bake
# lock is still held — so the layer file and its manifest entry land together
# (closes the PHP-records-AFTER-bash-released-the-lock drift window that the 7 s
# reconcile loop + boot-integrity heuristics exist to patch). We record the EXACT
# layer just written (not a glob-newest, which can race). Idempotent (addLayer
# upserts by filename). F6 (WP#1331): this is the SOLE synchronous recorder — the PHP
# commitChanges belt-and-braces path was removed in this epic — so the lifecycle event
# is gated on the writer's SUCCESS (it no longer fires when the write failed) and the
# supervisor reconcile is the only remaining backstop.
_MANIFEST_RECORDED=0
if manifest_record_layer "$TYPE" "$ID" "$PERSIST_PATH" "$NEW_BASENAME"; then
    _MANIFEST_RECORDED=1
    lifecycle_log "info" "commit_stack" "manifest_recorded_under_lock" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\"}" 2>/dev/null || true
else
    lifecycle_log "warn" "commit_stack" "manifest_record_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$NEW_BASENAME\"}" 2>/dev/null || true
fi

# 3. Check if ZRAM can be safely flushed
if declare -f agent_mount >/dev/null 2>&1; then
    # SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): route through
    # resolve_paths.sh's agent_mount() (sourced above), falling back to the
    # literal only if sourcing ever failed.
    MNT_POINT="$(agent_mount "$ID")"
else
    MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
fi
[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"

log "Checking for active sessions on $MNT_POINT..."

# WP #1081 (2026-05-24): SINGLE fuser check immediately before any destructive op,
# then refresh FIRST and cleanup SECOND. The prior structure (WP #1080) added a
# second fuser check before the refresh but left selective_upper_cleanup BETWEEN
# the two checks — a 10-second window during which an agent could launch, then
# the cleanup would remove a file from upper that was in the new lower (but the
# mount wasn't yet refreshed to expose the new lower), causing the file to
# briefly vanish from the agent's view. Restructuring so cleanup runs AFTER
# refresh closes that race: removing files from upper after refresh is safe
# because the new lower (with those files) is exposed.
#
# Why refresh-first is safe: the bake already wrote the new sqsh atomically via
# atomic_write_layer.sh (tempfile + fsync + verify + rename). UPPER is unchanged
# by the bake. Refreshing the mount picks up the new lower without touching
# upper. Then cleanup runs on a mount where the new lower is exposed, so any
# file removed from upper falls through to the new lower (correct content).
#
# Residual race: the umount-remount inside mount_stack.sh has a brief window
# (microseconds) where the mountpoint is unmounted. An agent that launches in
# that exact window still dies with ENOENT. The fuser check below is the
# narrow-window mitigation. A full fix would require per-entity locks that
# agent launches respect; deferred as a future hardening pass.
SQSH_BYTES=$(stat -c '%s' "$NEW_SQSH" 2>/dev/null || echo 0)
# WP #1253-follow-up (ENOENT race): use home_mount_in_use, NOT a bare fuser test.
# fuser only sees open fds / cwd / exe on the fs; agent sessions hold HOME=<mount>
# as an env var with cwd in the workspace, touching HOME transiently — so fuser
# reports the home mount idle while a session is live, the refresh umount/remounts
# under it, and the agent's next HOME write hits the unmounted window (ENOENT,
# e.g. claude's mkdir ~/.claude/session-env/<uuid>). home_mount_in_use detects a
# live interactive session (a ttyd carrying AICLI_HOME=<mount>) so the refresh
# DEFERS while a user is connected and reclaim only runs when the home is idle.
# (It deliberately does NOT block on the plugin's permanent HOME=<mount> daemons —
# session dbus / secret-service — which would otherwise defer reclaim forever.)
# The bake above already persisted to Flash, so deferring loses no data — only
# ZRAM reclamation waits.
if home_mount_in_use "$MNT_POINT"; then
    log "Mount is BUSY (open fd or live HOME=$MNT_POINT session). Deferring refresh + ZRAM cleanup."
    log "Data persisted to Flash. ZRAM dirty stats remain until sessions close."
    rm -f "$MARKER"
    # While the same live mount remains busy, UPPER is never trimmed. Therefore
    # this layer contains everything in the previous busy snapshot plus newer
    # writes. Keep one rolling crash-recovery snapshot instead of consuming a
    # layer slot on every scheduled bake. op_mount clears the marker before a
    # snapshot can become part of a lower stack.
    if [ "$TYPE" = "home" ] && [ "$_HOME_BUSY_AT_BAKE_START" -eq 1 ] \
       && [ "$_MANIFEST_RECORDED" -eq 1 ]; then
        _REPLACED_BUSY_SNAPSHOT=""
        if _replace_busy_snapshot "$TYPE" "$ID" "$PERSIST_PATH" \
                "$_BUSY_SNAPSHOT_MARKER" "$NEW_BASENAME"; then
            if [ -n "$_REPLACED_BUSY_SNAPSHOT" ]; then
                log "Replaced superseded busy snapshot $_REPLACED_BUSY_SNAPSHOT with $NEW_BASENAME"
                lifecycle_log "info" "commit_stack" "busy_snapshot_replaced" \
                    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"previous\":\"$_REPLACED_BUSY_SNAPSHOT\",\"current\":\"$NEW_BASENAME\"}" 2>/dev/null || true
            else
                log "Recorded first rolling busy snapshot $NEW_BASENAME"
            fi
        else
            _busy_roll_rc=$?
            if [ "$_busy_roll_rc" -eq 2 ]; then
                log "Mount stack changed during bake; keeping the new snapshot as a normal layer for safety"
            else
                log "Could not roll the busy snapshot; keeping every layer for safety"
            fi
        fi
    fi
    # Stamp the busy-bake cooldown: while a session keeps the home busy, the
    # upper is never trimmed (reclaim deferred), so without this every bake
    # trigger would re-bake the whole untrimmed upper. The pre-bake gate near
    # the top skips redundant bakes until this cooldown elapses. (home only.)
    [ "$TYPE" = "home" ] && date +%s > "/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}" 2>/dev/null || true
    _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_busy" "mount_busy" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES}"
fi

# Refresh mount stack to pick up the new lower layer. Done BEFORE selective
# cleanup so the new lower is exposed when we start removing files from upper.
log "Refreshing mount stack..."
# WP #1309: op_mount can now DEFER (exit 2) if the mount went busy in the race
# between the home_mount_in_use check above and here (a session launched in the
# gap). The bake already persisted to Flash, so deferring loses NO data — we must
# only skip the selective ZRAM cleanup (never wipe the upper while the new lower
# isn't yet exposed) and defer reclaim to the next idle tick, exactly as the
# home_mount_in_use branch above does. `if op_mount; then` keeps `set -e` from
# firing so we can capture the exit code.
if op_mount "$TYPE" "$ID" "$PERSIST_PATH"; then
    _REFRESH_RC=0
else
    _REFRESH_RC=$?
fi
if [ "$_REFRESH_RC" -ne 0 ]; then
    rm -f "$MARKER"
    if [ "$_REFRESH_RC" -eq 2 ]; then
        log "Mount refresh deferred (busy) — data persisted to Flash; ZRAM reclaim deferred to idle."
        # Stamp the busy-bake cooldown (home only) so the pre-bake gate skips
        # redundant re-bakes of the still-untrimmed upper until idle.
        [ "$TYPE" = "home" ] && date +%s > "/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}" 2>/dev/null || true
        _op_defer "$TYPE" "$ID" "commit_stack" "bash_bake_refresh_deferred" "mount_busy" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\"}"
    fi
    # Hard failure (exit 1+): preserve the upper (skip reclaim) and surface it.
    error "Post-bake mount refresh failed (rc=$_REFRESH_RC) — preserving upper, skipping reclaim."
    lifecycle_log "error" "commit_stack" "bash_bake_refresh_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"rc\":$_REFRESH_RC}" 2>/dev/null || true
    exit "$_REFRESH_RC"
fi

# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15) — THE ONE RULE THAT MUST
# NOT BE GOT WRONG.
#
# Everything below reclaims bytes from UPPER_DIR on the strength of one
# invariant: the mount that reads this upper has just been refreshed, so every
# file removed here falls through to the new lower and the merged view does not
# change. Side by side breaks that invariant for exactly one case. When the
# refresh above bound a NEW generation, it did not touch the generation this
# bake read its upper from — that generation is still mounted, still serving a
# live session, and its own lower stack does NOT contain the layer just baked.
# Trimming its upper would delete files out from under a running agent with
# nothing underneath to fall through to.
#
# So: trim only when the generation that owns this upper is still the live one.
# Otherwise leave it entirely alone — the whole upper is reclaimed in one go
# when that generation is reaped, which cannot happen until nothing references
# it any more.
_SKIP_UPPER_TRIM=0
if [ -n "$_UPPER_OVERRIDE" ]; then
    # A staging layer: its whole point is to be captured once and then removed
    # by the caller. Trimming it here would be work done twice, against a layer
    # this function does not own.
    _SKIP_UPPER_TRIM=1
    rm -f "$MARKER" "$_BAKE_MANIFEST" 2>/dev/null || true
elif [ "$TYPE" = "agent" ] && [ -n "${ENTITY_GENERATION:-}" ] \
   && declare -f agent_live_generation >/dev/null 2>&1; then
    _LIVE_GEN_NOW="$(agent_live_generation "$ID" 2>/dev/null || true)"
    if [ "$_LIVE_GEN_NOW" != "$ENTITY_GENERATION" ]; then
        _SKIP_UPPER_TRIM=1
        log "Generation $ENTITY_GENERATION was superseded by ${_LIVE_GEN_NOW:-none} during this bake — leaving its writable layer intact (a session is still running from it)."
        lifecycle_log "info" "commit_stack" "upper_trim_skipped_superseded_generation" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"baked_generation\":\"$ENTITY_GENERATION\",\"live_generation\":\"${_LIVE_GEN_NOW:-}\"}" 2>/dev/null || true
        rm -f "$MARKER" "$_BAKE_MANIFEST" 2>/dev/null || true
    fi
fi

# WP #935: Selective UPPER cleanup — now safe to run because the refresh above
# exposed the new lower. Per-file: a file is wiped only if (a) its mtime is not
# newer than the marker AND (b) no process holds an open write fd to it. Bytes
# that satisfy the invariant are reclaimed; the rest stay in ZRAM safely.
if [ "$_SKIP_UPPER_TRIM" = "0" ]; then
    log "Performing selective ZRAM cleanup (per-file mtime + open-fd + bake-confirmed invariant)..."
    # WP #1277: build the confirmed-baked manifest of ABSOLUTE upper paths from the
    # writer's relative file list, then pass it as the third arg so the reclaim only
    # wipes files PROVEN in this layer. The manifest is passed UNCONDITIONALLY (even
    # if empty) — never fall back to the legacy candidates−excludes wipe, which is
    # the aggressive behaviour that risked loss. An empty manifest => wipe nothing
    # (the upper is simply not trimmed this cycle; a later bake reclaims it).
    CONFIRMED_MANIFEST="${_BAKE_MANIFEST}.abs"
    : > "$CONFIRMED_MANIFEST"
    if [ -s "$_BAKE_MANIFEST" ]; then
        while IFS= read -r _rel; do
            [ -n "$_rel" ] && printf '%s\n' "$UPPER_DIR/$_rel"
        done < "$_BAKE_MANIFEST" >> "$CONFIRMED_MANIFEST"
    fi
    CLEANUP_JSON=$(selective_upper_cleanup "$UPPER_DIR" "$MARKER" "$CONFIRMED_MANIFEST")
    rm -f "$MARKER" "$_BAKE_MANIFEST" "$CONFIRMED_MANIFEST" 2>/dev/null || true
    # Forgejo #298 (2026-09-23): the caches were excluded from the layer, so the
    # reclaim above kept them (it removes only files proven in the layer). Drop
    # them from the upper here, but ONLY while nothing uses the mount. A live
    # session keeps its cache files. For an agent, check the REAL generation
    # mount: /proc/mounts never names the stable symlink.
    _PRUNE_CHECK_MNT="$MNT_POINT"
    if [ "$TYPE" = "agent" ] && declare -f agent_mount_real >/dev/null 2>&1; then
        _PRUNE_CHECK_MNT="$(agent_mount_real "$ID" 2>/dev/null || echo "$MNT_POINT")"
    fi
    if ! _bake_prune_upper_if_idle "$TYPE" "$UPPER_DIR" "$_PRUNE_CHECK_MNT"; then
        log "Mount $_PRUNE_CHECK_MNT is in use — keeping cache files in the writable layer (they are not in the layer)."
    fi
else
    CLEANUP_JSON='{"skipped":"superseded_generation"}'
fi
# #372: the bake read the live upper of the OLD mode (the policy changed while
# the home was mounted). The reclaim above trimmed what this layer holds; when
# that upper now holds no data and nothing uses the home, remount it on the
# policy upper. Otherwise it stays, and a later bake tries again.
if [ "$_SKIP_UPPER_TRIM" = "0" ] && [ "$TYPE" = "home" ] \
   && [ "${ENTITY_UPPER_MODE:-}" != "${ENTITY_POLICY_UPPER_MODE:-${ENTITY_UPPER_MODE:-}}" ]; then
    _home_upper_converge "$ID" "$PERSIST_PATH" "$UPPER_DIR" || true
fi

# Idle reclaim succeeded — the upper is trimmed, so clear any busy-bake cooldown
# left over from a prior in-session bake. The next session starts fresh.
[ "$TYPE" = "home" ] && rm -f "/tmp/unraid-aicliagents/.bake_busy_cooldown_${TYPE}_${_LOCK_ID}" 2>/dev/null || true

# WP #1081: WORK_DIR wipe REMOVED. Previously this swept overlayfs whiteout/work
# files after cleanup. But under the refresh-then-cleanup order, the overlay is
# already remounted with a live kernel fd on $WORK_DIR/work; wiping that subdir
# from userspace mid-mount breaks copy-up (smoke A10 reproduced: fopen on a new
# file in the merged view returned ENOENT until the next mount cycle). The wipe
# was purely cosmetic kernel-scratch reclamation — overlayfs clears workdir
# contents on its OWN mount, so leftover bytes don't accumulate across cycles.
sync

# Inline the selective-cleanup stats into the bake_ok event for observability.
# CLEANUP_JSON is a JSON object; splice into the outer event.
lifecycle_log "info" "commit_stack" "bash_bake_ok" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$(basename "$NEW_SQSH")\",\"bytes\":$SQSH_BYTES,\"cleanup\":$CLEANUP_JSON}" 2>/dev/null || true

# #338: release the agent layers the retention rule no longer keeps. The sweep
# inside the refresh above could not (this bake holds the storage lock it
# takes); this bake has recorded its layer and the refresh has run, so it runs
# here under the same lock. House-keeping: never changes this bake's result.
if [ "$TYPE" = "agent" ]; then
    source "$_SO_DIR/../installer/generation.sh" 2>/dev/null || true
    if declare -f aicli_gc_agent_layers >/dev/null 2>&1; then
        _LGC_OUT="$(AICLI_AGENT_LAYER_LOCK_HELD=1 aicli_gc_agent_layers "$ID" "$PERSIST_PATH" 2>&1 || true)"
        [ -n "$_LGC_OUT" ] && log "$_LGC_OUT"
    fi
fi
exit 0
)

# ---- Forgejo #298 (2026-09-23): bake cache exclusion helpers -----------------
# docs/specs/HOME_STORAGE_LIFECYCLE.md "2026-09-23 — caches are excluded, not
# deleted". These helpers are PURE (no mount, no lock). tests/unit/
# bake_cache_exclude_test.sh drives them directly. They live AFTER op_bake on
# purpose: RegressionGuardsTest anchors the first ".npm" in this file after
# op_bake's `flock -n 9`.

# _bake_prune_patterns <type>
# Print the mksquashfs -wildcards exclude patterns, one for each line, relative
# to the bake root. "dir/*" excludes the CONTENTS and keeps the directory.
# A deleted directory in an overlay upper hides the lower's copy with an opaque
# whiteout, and agents then cannot create new cache files there. Home only:
# .bun/install and three .claude caches (whole directories, as before).
# WP #931: .gemini/tmp holds gemini-cli chat logs — never list it.
# HARD CONSTRAINT: never list snap_*/migrated_legacy_data/*backup*/SAFE_BACKUP.
_bake_prune_patterns() {
    local type="${1:-}"
    printf '%s\n' '.npm/*' '.cache/*' 'tmp/*'
    if [ "$type" = "home" ]; then
        printf '%s\n' '.bun/install' '.claude/cache' '.claude/shell-snapshots' '.claude/telemetry'
    fi
}

# _bake_args_with_excludes <mksquashfs args> <exclude file>
# Add "-wildcards -ef <file>" to a mksquashfs argument string. mksquashfs
# reads every word after "-e" as an exclude, so the new options go BEFORE a
# trailing "-e ..." list. Print the new string.
_bake_args_with_excludes() {
    local args="${1:-}" ef="${2:-}" head tail
    [ -n "$ef" ] || { printf '%s' "$args"; return 0; }
    case " $args " in
        *" -ef $ef "*) printf '%s' "$args"; return 0 ;;
    esac
    case " $args " in
        *" -e "*)
            head="${args%% -e *}"
            tail="${args#"$head"}"
            printf '%s -wildcards -ef %s%s' "$head" "$ef" "$tail"
            ;;
        *) printf '%s%s-wildcards -ef %s' "$args" "${args:+ }" "$ef" ;;
    esac
}

# _bake_upper_has_content <type> <upper>
# True (0) when the upper holds anything the bake will capture: an entry that
# is not inside an excluded cache and is not only a parent directory of one.
# find prunes the excluded subtrees, so a large cache costs no extra walk.
_bake_upper_has_content() {
    local type="${1:-}" upper="${2:-}" e rel p parent
    [ -d "$upper" ] || return 1
    local -a pats=() prune=()
    mapfile -t pats < <(_bake_prune_patterns "$type")
    for p in "${pats[@]}"; do
        [ -n "$p" ] || continue
        [ "${#prune[@]}" -gt 0 ] && prune+=(-o)
        prune+=(-path "$upper/$p")
    done
    while IFS= read -r -d '' e; do
        rel="${e#"$upper"/}"
        if [ -d "$e" ] && [ ! -L "$e" ]; then
            parent=0
            for p in "${pats[@]}"; do
                case "$p" in "$rel"/*) parent=1; break ;; esac
            done
            [ "$parent" -eq 1 ] && continue
        fi
        return 0
    done < <(find "$upper" -mindepth 1 \( "${prune[@]}" \) -prune -o -print0 2>/dev/null)
    return 1
}

# _bake_prune_upper <type> <upper>
# Remove the excluded caches from the upper. Call it ONLY after a successful
# bake and ONLY when the mount is idle. The ".npm/*" style patterns remove the
# CONTENTS and keep the directory (see _bake_prune_patterns); the others remove
# the whole directory, as the pre-#298 code did.
_bake_prune_upper() {
    local type="${1:-}" upper="${2:-}" p root
    [ -n "$upper" ] && [ -d "$upper" ] || return 0
    while IFS= read -r p; do
        [ -n "$p" ] || continue
        case "$p" in
            */\*)
                root="${p%/\*}"
                [ -d "$upper/$root" ] && find "$upper/$root" -mindepth 1 -delete 2>/dev/null
                ;;
            *)
                [ -d "$upper/$p" ] && rm -rf "${upper:?}/$p" 2>/dev/null
                ;;
        esac
    done < <(_bake_prune_patterns "$type")
    return 0
}

# _bake_prune_upper_if_idle <type> <upper> <mount>
# Remove the excluded caches from <upper> only when home_mount_in_use says
# that nothing uses <mount>. Return 1 (and remove nothing) when it is in use.
# A mount check that is not available counts as "in use": fail safe.
_bake_prune_upper_if_idle() {
    local type="${1:-}" upper="${2:-}" mnt="${3:-}"
    declare -f home_mount_in_use >/dev/null 2>&1 || return 1
    home_mount_in_use "$mnt" && return 1
    _bake_prune_upper "$type" "$upper"
}

# ---- op_rebase_agent  (#338, 2026-09-26) --------------------------------------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent layer
# retention", "Migration of existing long stacks" (2). A one-time consolidation
# of an IDLE layered agent whose active stack is 2+ layers: write ONE base
# holding the same package, switch to it, and let the retention sweep release
# the replaced stack. Nothing runs from the agent (checked twice), and its
# writable layer is empty, so the mounted active generation shows exactly its
# read-only stack — that view is the source. Exit 0 done (or not due: event
# agent_rebase_not_due), 2 deferred, 1 failed. Never deletes a layer itself.
op_rebase_agent() (
set -euo pipefail
ID="${1:-}"
PERSIST_PATH="${2:-}"
TYPE="agent"
source "$_SO_DIR/common.sh"
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || { echo "[REBASE] atomic_write_layer.sh missing" >&2; exit 1; }
source "$_SO_DIR/../installer/generation.sh" 2>/dev/null || { echo "[REBASE] generation.sh missing" >&2; exit 1; }
log()   { local m="[$(get_ts)] [INFO] [REBASE] $(_trace_tag)$1"; echo "$m"; echo "$m" >> "$DEBUG_LOG"; }
error() { local m="[$(get_ts)] [ERR!] [REBASE] $(_trace_tag)$1"; echo "$m"; echo "$m" >> "$DEBUG_LOG"; }
[ -n "$ID" ] && [ -n "$PERSIST_PATH" ] || { error "usage: op_rebase_agent <agent_id> <persist>"; exit 1; }
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# The storage lock: a bake, consolidate, restore and reconcile of this agent
# all take it. Non-blocking: a rebase is never urgent.
_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
exec 9>"/var/run/aicli-bake-agent-${_LOCK_ID}.lock"
if ! flock -n 9; then
    log "Agent $ID: another storage operation holds the lock — rebase deferred."
    _op_defer "$TYPE" "$ID" "rebase" "agent_rebase_deferred" "bake_lock_held"
fi

if ! _WHY="$(aicli_agent_rebase_wanted "$ID" "$PERSIST_PATH")"; then
    log "Agent $ID: no rebase due ($_WHY)."
    lifecycle_log "info" "rebase" "agent_rebase_not_due" "{\"id\":\"$ID\",\"reason\":\"$_WHY\"}" 2>/dev/null || true
    exit 0
fi

_GEN="$(agent_live_generation "$ID")"
_TOP="$(aicli_agent_generation_top "$_GEN")"
_VIEW="$(agent_versioned_mount "$ID" "$_GEN")"
_UPPER="$(agent_generation_state_get "$ID" "$_GEN" upper 2>/dev/null || true)"
mapfile -t _OLD_STACK < <(aicli_agent_layer_names "$ID" "$PERSIST_PATH" | aicli_layer_stack_of "$_TOP")
log "Agent $ID: rebasing ${#_OLD_STACK[@]} layer(s) (top $_TOP) into one base from $_VIEW."
lifecycle_log "info" "rebase" "agent_rebase_start" \
    "{\"id\":\"$ID\",\"generation\":\"$_GEN\",\"layers\":${#_OLD_STACK[@]}}" 2>/dev/null || true

# Room for the new base: the largest layer of the stack is about one package
# (an npm install rewrites the whole tree). Never START a write whose result
# cannot fit (#338: .4 reached 100 % /boot and every bake then failed).
_OLD_PATHS=()
for _l in "${_OLD_STACK[@]}"; do _OLD_PATHS+=("$PERSIST_PATH/$_l"); done
_EST_BYTES="$(_layer_largest_bytes "${_OLD_PATHS[@]}")"
if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
    error "Agent $ID: the new base needs about ${_FIT_NEED_MB} MB (estimate plus margin), ${_FIT_FREE_MB} MB free on $PERSIST_PATH — rebase skipped, nothing written."
    lifecycle_log "warn" "rebase" "agent_rebase_no_space" \
        "{\"id\":\"$ID\",\"need_mb\":$_FIT_NEED_MB,\"free_mb\":\"$_FIT_FREE_MB\",\"estimate_bytes\":$_EST_BYTES}" 2>/dev/null || true
    write_defer_reason "$TYPE" "$ID" "no_space"
    exit 2
fi

# The same cache excludes as every bake.
_EXCL="/tmp/unraid-aicliagents/.rebase_excludes_${_LOCK_ID}"
_bake_prune_patterns "$TYPE" > "$_EXCL" 2>/dev/null || { error "Could not write the exclude list $_EXCL"; exit 1; }
MKSQUASHFS_ARGS="$(_bake_args_with_excludes "${MKSQUASHFS_ARGS:-${_AWL_DEFAULT_ARGS:--comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend}}" "$_EXCL")"
export MKSQUASHFS_ARGS

if ! _NEW="$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$_VIEW" "consolidated")"; then
    rm -f "$_EXCL"
    error "Agent $ID: writing the base failed — nothing changed."
    lifecycle_log "error" "rebase" "agent_rebase_failed" "{\"id\":\"$ID\",\"reason\":\"write_failed\"}" 2>/dev/null || true
    exit 1
fi
rm -f "$_EXCL"

# Check again: the view read must still be the idle, empty-upper, same
# generation it was. Otherwise the base may miss something: remove it.
_rebase_discard() {
    rm -f "$PERSIST_PATH/$_NEW" 2>/dev/null || true
    error "Agent $ID: $1 — the new base $_NEW is removed; nothing changed."
    lifecycle_log "warn" "rebase" "agent_rebase_discarded" "{\"id\":\"$ID\",\"reason\":\"$2\",\"sqsh\":\"$_NEW\"}" 2>/dev/null || true
}
if [ "$(agent_live_generation "$ID" 2>/dev/null || true)" != "$_GEN" ]; then
    _rebase_discard "the active generation changed during the rebase" "generation_changed"; exit 2
fi
if aicli_agent_has_live_processes "$ID"; then
    _rebase_discard "a process started using the agent during the rebase" "in_use"
    write_defer_reason "$TYPE" "$ID" "mount_busy"; exit 2
fi
if _dir_has_entries "$_UPPER"; then
    _rebase_discard "the writable layer is no longer empty" "writable_layer_changed"
    write_defer_reason "$TYPE" "$ID" "upper_not_empty"; exit 2
fi
if [ "$(aicli_agent_layer_names "$ID" "$PERSIST_PATH" | head -1)" != "$_NEW" ]; then
    _rebase_discard "another layer landed during the rebase" "layer_landed"; exit 2
fi
# Content check: the base lists as many entries as the view shows (outside the
# excluded caches). A short base would drop part of the package.
if command -v unsquashfs >/dev/null 2>&1; then
    _VIEW_N="$(cd "$_VIEW" && find . -mindepth 1 2>/dev/null | grep -vE '^\./(\.npm|\.cache|tmp)/' | grep -c . || true)"
    _BASE_N="$(unsquashfs -l "$PERSIST_PATH/$_NEW" 2>/dev/null | grep -c '^squashfs-root/' || true)"
    if [ "${_VIEW_N:-0}" != "${_BASE_N:-0}" ]; then
        _rebase_discard "the base lists ${_BASE_N:-0} entries, the view shows ${_VIEW_N:-0}" "content_mismatch"; exit 1
    fi
fi

# Write-ahead intent: the replaced stack is the SAME package as the base, so the
# retention sweep must not keep it as the previous version (K4). It survives a
# crash; the sweep clears it when every listed layer is gone.
_DEL=""
for _l in "${_OLD_STACK[@]}"; do _DEL="${_DEL:+$_DEL,}\"$_l\""; done
# R-020 (#598): the intent comes before the manifest record, so a failed write
# leaves the manifest untouched and the new base is simply discarded.
if ! write_intent "$PERSIST_PATH" "$TYPE" "$ID" "{\"op\":\"agent_rebase\",\"keep\":\"$_NEW\",\"delete\":[$_DEL]}"; then
    _rebase_discard "the delete plan could not be written" "intent_write_failed"; exit 1
fi
if ! manifest_record_layer "$TYPE" "$ID" "$PERSIST_PATH" "$_NEW"; then
    clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
    _rebase_discard "the manifest could not record the base" "manifest_record_failed"; exit 1
fi

# Switch: a normal refresh binds the generation of the new base and moves the
# stable name onto it. The old generation is unreferenced and is released.
if op_mount "$TYPE" "$ID" "$PERSIST_PATH"; then _MRC=0; else _MRC=$?; fi
if [ "$_MRC" -ne 0 ]; then
    error "Agent $ID: the switch to $_NEW did not complete (rc=$_MRC); the next mount switches, and the sweep then releases the replaced stack."
    lifecycle_log "warn" "rebase" "agent_rebase_switch_deferred" "{\"id\":\"$ID\",\"sqsh\":\"$_NEW\",\"rc\":$_MRC}" 2>/dev/null || true
    exit 2
fi
if [ "$(aicli_agent_generation_top "$(agent_live_generation "$ID" 2>/dev/null || true)" 2>/dev/null || true)" != "$_NEW" ]; then
    error "Agent $ID: the mount did not switch to $_NEW — the replaced stack stays."
    lifecycle_log "warn" "rebase" "agent_rebase_switch_deferred" "{\"id\":\"$ID\",\"sqsh\":\"$_NEW\",\"rc\":0}" 2>/dev/null || true
    exit 2
fi

_OUT="$(AICLI_AGENT_LAYER_LOCK_HELD=1 aicli_gc_agent_layers "$ID" "$PERSIST_PATH" 2>&1 || true)"
[ -n "$_OUT" ] && log "$_OUT"
lifecycle_log "info" "rebase" "agent_rebase_ok" \
    "{\"id\":\"$ID\",\"sqsh\":\"$_NEW\",\"replaced\":${#_OLD_STACK[@]},\"bytes\":$(stat -c '%s' "$PERSIST_PATH/$_NEW" 2>/dev/null || echo 0)}" 2>/dev/null || true
log "Agent $ID: rebased onto $_NEW."
exit 0
)

# ---- op_consolidate  (from consolidate_layers.sh) ---------------
op_consolidate() (
set -euo pipefail
# AICliAgents: Storage Consolidation & Volume Splitting
# Usage: consolidate_layers.sh <type: agent|home> <id> <persistence_path>

TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"

# Source canonical path resolver (Phase 1 — Storage Durability Supervisor).
# Moved ahead of the MNT_POINT derivation below (SIDE_BY_SIDE_AGENT_INSTALLS.md
# Phase 1, 2026-09-09) so agent_mount() is actually available by the time it's
# needed here; this is a subshell function (note the outer `(` `)`), so a
# source further down in the SAME function still would not leak in from any
# earlier op_* call — each op_* function must source it for itself.
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true

if declare -f agent_mount >/dev/null 2>&1; then
    MNT_POINT="$(agent_mount "$ID")"
else
    MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$ID"
fi

# Source shared storage functions (guard_path, check_disk_space, etc.)
source "$_SO_DIR/common.sh"

# WP #922: snapshot debug.log to Flash on non-zero exit. Survives /tmp rotation
# so the next investigator has actual evidence. Skips on exit 2 (deferred).
install_failure_trap "$TYPE" "$ID" "consolidate_layers"

# F6 (WP#1331): the SINGLE manifest writer (replaces the inline php -r replaceLayers).
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true

# Source atomic layer writer (Phase 2)
source "$_SO_DIR/atomic_write_layer.sh" 2>/dev/null || {
    echo "[CONSOLIDATE] FATAL: atomic_write_layer.sh missing — cannot consolidate safely" >&2
    exit 1
}

[ "$TYPE" == "home" ] && MNT_POINT="/tmp/unraid-aicliagents/work/$ID/home"
TASK_STATUS_FILE="/tmp/unraid-aicliagents/task-status-$ID"

# #342: derive UPPER_DIR from persistence fstype — must match mount_stack.sh.
_entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"   # sets UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE  # Phase 3: the LIVE generation's layer, not the id-keyed one

# Forgejo #296 (2026-09-23): remember WHICH writable layer is live now. Every
# mount call below must leave this same layer behind the view that the bake
# reads. _consol_recheck_binding compares against it.
_CONSOL_BASE_UPPER="$UPPER_DIR"
# The registry binary, relative to the agent mount. The installer exports
# AICLI_AGENT_BINARY; other callers do not, and then only the generic
# top-level check in _view_lost_upper_content runs.
_CONSOL_BIN_REL=""
[ "$TYPE" = "agent" ] && _CONSOL_BIN_REL="$(_agent_binary_rel "$ID" "${AICLI_AGENT_BINARY:-}")"
_CONSOL_BIN_SEEN=0

log() {
    local msg="[$(get_ts)] [INFO] [CONSOLIDATE] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg="[$(get_ts)] [ERR!] [CONSOLIDATE] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

# D-280: Task Status Updater for Frontend Progress Bars
update_task_status() {
    local step="$1"
    local progress="$2"
    local reason="${3:-}"
    # Sanitize step/reason to prevent JSON injection (strip quotes and backslashes)
    step="${step//\"/}"
    step="${step//\\/}"
    reason="${reason//\"/}"
    reason="${reason//\\/}"
    local completed="false"
    [ "$progress" -ge 100 ] && completed="true"
    printf '{"step":"%s","progress":%d,"completed":%s,"timestamp":%d,"reason":"%s"}' \
        "$step" "$progress" "$completed" "$(date +%s)" "$reason" > "$TASK_STATUS_FILE"
}

# Forgejo #454: tell the Activity tray which step of the merge is running.
# The job id comes from the supervisor in AICLI_JOB_ID. With no job id (a
# manual run, a test) nothing is set. Never fails the merge.
_consol_phase() {
    [ -n "${AICLI_JOB_ID:-}" ] || return 0
    # #494: job_ledger_set_phase lives in supervisor/queue_helpers.sh, which no
    # storage script loaded, so this was a no-op since #454 and the tray said
    # "Closing sessions" for the whole merge. Load it once, as backup_home.sh does.
    if ! declare -f job_ledger_set_phase >/dev/null 2>&1; then
        local _qh="${_SO_DIR:-$(dirname "${BASH_SOURCE[0]}")}/../supervisor/queue_helpers.sh"
        # shellcheck disable=SC1090
        [ -f "$_qh" ] && source "$_qh" 2>/dev/null || true
    fi
    declare -f job_ledger_set_phase >/dev/null 2>&1 || return 0
    job_ledger_set_phase "$AICLI_JOB_ID" "$1" 2>/dev/null || true
}

# Forgejo #454: hook that atomic_write_layer calls with the percentage that
# mksquashfs prints. The write phase covers progress 40 to 78.
_consol_bake_progress() {
    local pct="${1:-0}"
    case "$pct" in ''|*[!0-9]*) return 0 ;; esac
    [ "$pct" -gt 100 ] && pct=100
    update_task_status "Writing the new layer (can take several minutes)" $((40 + pct * 38 / 100)) ""
}
export AICLI_BAKE_PROGRESS_HOOK=_consol_bake_progress

# _consol_recheck_binding <stage> [layer_to_discard]
# Forgejo #296 (2026-09-23). Derive the live writable layer again and compare
# it with the baseline. If a mount call bound a different layer while the
# baseline still holds data, the view does not contain that data: stop before
# anything destructive. Remove <layer_to_discard> (a layer this run baked from
# the wrong view) and defer (exit 2). The caller's fallback delta bake then
# captures the live layer. An adopted or empty baseline becomes the new
# baseline, because nothing can be lost.
_consol_recheck_binding() {
    local _stage="$1" _discard="${2:-}" _saved_mnt="$MNT_POINT"
    _entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"
    MNT_POINT="$_saved_mnt"
    if ! _upper_rebound_loses_data "$_CONSOL_BASE_UPPER" "$UPPER_DIR"; then
        _CONSOL_BASE_UPPER="$UPPER_DIR"
        return 0
    fi
    error "Forgejo #296: the live writable layer changed ($_stage) from $_CONSOL_BASE_UPPER to $UPPER_DIR, and the old layer holds data that is not baked. Refusing to consolidate from a view without it. Deferring."
    [ -n "$_discard" ] && rm -f "$_discard" 2>/dev/null
    rm -f "${CONSOLIDATE_MARKER:-}" 2>/dev/null || true
    UPPER_DIR="$_CONSOL_BASE_UPPER"
    update_task_status "Deferred" 0 "Agent version changed during consolidation — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"upper_rebound\",\"stage\":\"$_stage\",\"generation\":\"${ENTITY_GENERATION:-}\"}" "warn"
}

# WP #1078: clear any stale defer-reason marker from a prior consolidate run
# so PHP reads THIS run's reason on any exit-2 path. Sanitise the ID the same
# way write_defer_reason does so the rm matches the eventual write.
_DEFER_REASON_ID="${ID//[^a-zA-Z0-9_-]/_}"
rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_DEFER_REASON_ID}" 2>/dev/null || true

# Validate paths before any mount or destructive operations
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; update_task_status "Failed" 0 "Invalid path"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — consolidation refused"; update_task_status "Failed" 0 "Non-durable path"; exit 1; }
guard_path "$MNT_POINT" "MNT_POINT" || { error "Mount point failed validation: $MNT_POINT"; update_task_status "Failed" 0 "Invalid mount"; exit 1; }

# 1. Ensure stack is mounted to get merged view
lifecycle_log "info" "consolidate_layers" "bash_consolidate_start" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true
update_task_status "Initializing..." 5 ""
_consol_phase "pre-flight"
if ! mountpoint -q "$MNT_POINT"; then
    log "Stack not mounted. Attempting remount..."
    update_task_status "Mounting stack..." 10 ""
    # WP #1309: map op_mount's busy-defer (exit 2) to a consolidate defer, not a
    # hard failure — a busy race is "retry when idle", never an error.
    # Forgejo #296: refresh-only (6th arg) — never switch generation here.
    if op_mount "$TYPE" "$ID" "$PERSIST_PATH" "" 0 1; then :; else
        _ensure_rc=$?
        if [ "$_ensure_rc" -eq 2 ]; then
            log "Mount busy during consolidation pre-mount — deferring (retry when idle)."
            update_task_status "Deferred (mount busy)" 0 "Sessions active — will retry when idle"
            _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "mount_busy"
        fi
        error "Failed to mount stack for consolidation"
        update_task_status "Failed" 0 "Mount failed"
        exit 1
    fi
fi

# WP #922: pre-bake busy check. Match commit_stack.sh's safety pattern — if any
# process has open files on the merged mount, defer the consolidate rather than
# charging in. Three things go wrong if we ignore this:
#   1. The aggressive pre-bake prune (next block: rm -rf .npm, .cache, .claude
#      caches, etc.) hits files that are open for write by an active agent,
#      tripping `set -euo pipefail` and exiting 1 with no useful diagnostic.
#   2. mksquashfs sees the merged view mid-write and bakes an inconsistent
#      consolidated layer.
#   3. The supervisor counts this as a real failure and after 2 fails halts
#      auto-consolidate with a notification — even though the failure was just
#      a session being active.
#
# Exit 2 signals "deferred / busy" — supervisor treats this as not-a-failure
# and retries on next tick without incrementing the failure counter.
# Epic #1310 / ADR finding #2: use the SESSION-AWARE busy-arbiter, NOT a bare
# `fuser -sm`. A bare fuser only sees open fds/cwd/mmap on the mount and MISSES a
# live interactive session — a ttyd carrying AICLI_HOME=<mount> holds no fd, so
# fuser reads "idle" and consolidate would umount/remount the overlay out from
# under the session. home_mount_in_use (the same arbiter op_bake's reclaim uses)
# combines the fuser fast-path with the ttyd-session scan, so it defers correctly.
if _consol_mount_busy "$TYPE" "$ID" "$MNT_POINT"; then
    log "Mount is BUSY (open fd or live session on $MNT_POINT). Deferring consolidation — will retry when idle."
    update_task_status "Deferred (mount busy)" 0 "Sessions active — will retry when idle"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "mount_busy"
fi

# WP #1246: refresh the mount before reading the merged view for consolidation.
# If a prior bake's mount-refresh was interrupted (e.g. a daemon restart during
# `plugin install`), a baked delta can sit on flash WITHOUT being in the current
# overlay's lowerdir — and consolidating from that stale merged view bakes a
# consolidated layer that omits the delta's data, then deletes the orphan delta
# (permanent loss). op_mount re-discovers ALL on-disk layers (glob, not the live
# mount), so refreshing here guarantees consolidate reads a COMPLETE view. Safe:
# the fuser idle-check just above confirmed no open fds, so the umount-remount is
# clean (the same precondition op_bake's post-bake refresh relies on).
# WP #1309: a busy-defer (exit 2) here is "retry when idle", not a hard failure.
#
# Forgejo #296 (2026-09-23): the refresh is REFRESH-ONLY (6th arg). It must
# never move an agent to a newer generation: that generation has a different,
# empty writable layer, so an install that sits only in the live layer would
# drop out of the view, and the bake below would capture an empty agent.
# op_mount defers (exit 2) instead; FileStorage::persist then runs a delta bake,
# and that bake's own refresh makes the switch after the data is safe.
# Before the refresh, note whether the view shows the agent binary.
_consol_recheck_binding "before_refresh"
if [ -n "$_CONSOL_BIN_REL" ] && { [ -e "$MNT_POINT/$_CONSOL_BIN_REL" ] || [ -L "$MNT_POINT/$_CONSOL_BIN_REL" ]; }; then
    _CONSOL_BIN_SEEN=1
fi
if op_mount "$TYPE" "$ID" "$PERSIST_PATH" "" 0 1; then :; else
    _refresh_rc=$?
    if [ "$_refresh_rc" -eq 2 ]; then
        log "WP #1246 pre-consolidate refresh deferred (mount busy) — retry when idle."
        update_task_status "Deferred (mount busy)" 0 "Sessions active — will retry when idle"
        _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "mount_busy"
    fi
    error "WP #1246: pre-consolidate mount refresh failed — aborting rather than bake a stale view."
    update_task_status "Failed" 0 "Mount refresh failed"
    exit 1
fi

# WP #1278 (#3): lowerdir-completeness backstop. The refresh above re-discovers
# ALL on-disk layers and mounts them all-or-nothing, so in normal operation the
# live overlay's lowerdir count equals the on-disk layer count. Assert that
# BEFORE trusting the merged view enough to bake-and-delete: if the live overlay
# is SHORT (fewer lowers than layers on disk), some delta is absent from the
# view — consolidating would bake a lossy layer and the old-layer delete (step
# 4b below) would then destroy that delta permanently. This is the May-29 Tower
# vector (a stale/short consolidate lowerdir baked a consolidated missing the
# user's conversations). Abort with exit 2 (deferred — preserves the upper AND
# every delta; no marker/lock taken yet) so a later idle tick retries from a
# complete mount. Only enforced when >=1 layer exists on disk: the empty-stack
# mount uses a synthetic single EMPTY_LOWER which would otherwise read as a
# 1-vs-0 mismatch. A concurrent bake landing a delta between the refresh and this
# check trips it too — a benign deferral (retry picks up the new layer), never a
# loss, mirroring the bake-landed-during-consolidate guard further down.
# #338: compare with the layers a mount STACKS (an agent stack ends at its
# newest base), not every file: a retained previous generation below the base
# is on disk but, correctly, not in this overlay.
_DISCOVERED_COUNT=$(_layer_stack_sorted "$PERSIST_PATH" "$TYPE" "$ID" | awk 'NF{c++}END{print c+0}')
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): _mounted_lower_count reads
# /proc/mounts, which records the kernel's REAL mount target -- never the stable
# agent_mount symlink once an agent is migrated. Resolve through agent_mount_real
# first so this WP #1278 completeness check keeps seeing the true lowerdir count
# instead of reading 0 and deferring every consolidate forever (fail-safe, but
# not correct -- a genuinely stale/short mount would look identical, and the two
# must stay distinguishable). No-op for home and for a not-yet-migrated agent.
#
# 2026-09-29 (forum report, docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md
# "2026-09-29 — a consolidate reads the real directory, never the stable
# name"): the real directory is resolved ONCE, here, after the refresh, and
# EVERY read of the merged view below uses _CONSOL_VIEW: the completeness
# count, the prune, the orphan scan, the legacy home/ check, the FAT32
# projection, the SQLite scan and backup, and the bake source. Before this,
# only the count used it. The bake got the stable name, which is a symlink on
# the versioned layout, and mksquashfs packed only the link: a 4096-byte layer
# that then replaced the real agent layers.
_CONSOL_VIEW="$(_consol_view_dir "$TYPE" "$ID" "$MNT_POINT")"
if [ -z "$_CONSOL_VIEW" ] || [ -L "$_CONSOL_VIEW" ] || [ ! -d "$_CONSOL_VIEW" ] \
   || ! guard_path "$_CONSOL_VIEW" "CONSOL_VIEW" || ! mountpoint -q "$_CONSOL_VIEW"; then
    error "The merged view of $TYPE $ID ($MNT_POINT) does not resolve to a mounted real directory (got '${_CONSOL_VIEW:-}'). Refusing to consolidate. Deferring."
    update_task_status "Deferred" 0 "Merged view not found — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"view_not_resolved\"}" "warn"
fi
if [ "$_CONSOL_VIEW" != "$MNT_POINT" ]; then
    log "Merged view $MNT_POINT resolves to $_CONSOL_VIEW — the consolidate reads that directory."
fi
_MOUNTED_COUNT=$(_mounted_lower_count "$_CONSOL_VIEW")
case "$_MOUNTED_COUNT" in ''|*[!0-9]*) _MOUNTED_COUNT=0 ;; esac
if [ "$_DISCOVERED_COUNT" -ge 1 ] && [ "$_MOUNTED_COUNT" -ne "$_DISCOVERED_COUNT" ]; then
    error "WP #1278: mounted lowerdir count ($_MOUNTED_COUNT) != on-disk layer count ($_DISCOVERED_COUNT) after refresh — refusing to consolidate from an incomplete view (would risk deleting un-captured deltas). Deferring."
    update_task_status "Deferred" 0 "Incomplete mount — retry when stack is complete"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"lowerdir_incomplete\",\"mounted\":$_MOUNTED_COUNT,\"discovered\":$_DISCOVERED_COUNT}" "warn"
fi

# Forgejo #296 (2026-09-23): after the refresh, the SAME writable layer must be
# live, and the view must still show what that layer holds (and the agent
# binary, when the view showed it before). Otherwise stop now: nothing is
# baked or deleted yet.
_consol_recheck_binding "after_refresh"
if _view_lost_upper_content "$UPPER_DIR" "$_CONSOL_VIEW" "$_CONSOL_BIN_SEEN" "$_CONSOL_BIN_REL"; then
    error "Forgejo #296: the merged view at $_CONSOL_VIEW no longer shows content of the live writable layer $UPPER_DIR${_CONSOL_BIN_REL:+ (binary $_CONSOL_BIN_REL)}. Refusing to consolidate. Deferring."
    update_task_status "Deferred" 0 "Incomplete view — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"view_lost_upper_content\",\"binary\":\"$_CONSOL_BIN_REL\"}" "warn"
fi

# 2. Preparation: Pruning
if [ "$TYPE" == "agent" ]; then
    log "Pruning non-essential files..."
    update_task_status "Pruning non-essential files..." 20 ""
    # R-026 (#604): in a subshell, so the op's own working directory never stays
    # inside the mount (it made the later real umount fail with EBUSY).
    ( cd "$_CONSOL_VIEW" && npm prune --production > /dev/null 2>&1 ) || true
    rm -rf "$_CONSOL_VIEW/tmp/npm_cache"/* 2>/dev/null || true
fi

# WP #748 Phase 1 (F): expand pre-consolidate prune for home overlays.
# Operates on the MERGED mount path ($_CONSOL_VIEW) so the consolidated layer
# doesn't carry regenerable caches from lower layers either.
# HARD CONSTRAINT: never touch snap_*/migrated_legacy_data/*backup*/SAFE_BACKUP.
ORPHAN_N_EXCLUDES=""
if [ "$TYPE" = "home" ]; then
    log "Pruning regenerable caches from merged home view before consolidation..."
    update_task_status "Pruning caches..." 18 ""
    [ -d "$_CONSOL_VIEW/.npm" ] && find "$_CONSOL_VIEW/.npm" -mindepth 1 -delete 2>/dev/null || true
    [ -d "$_CONSOL_VIEW/.cache" ] && find "$_CONSOL_VIEW/.cache" -mindepth 1 -delete 2>/dev/null || true
    [ -d "$_CONSOL_VIEW/.bun/install" ] && rm -rf "$_CONSOL_VIEW/.bun/install" 2>/dev/null || true
    # WP #931: do NOT prune $_CONSOL_VIEW/.gemini/tmp — it contains gemini-cli's
    # project-scoped session chat logs (.gemini/tmp/<projectId>/chats/session-*.jsonl),
    # which are durable user state, not regenerable cache. Previously this line
    # silently wiped users' chat histories on every consolidate.
    [ -d "$_CONSOL_VIEW/.claude/cache" ] && rm -rf "$_CONSOL_VIEW/.claude/cache" 2>/dev/null || true
    [ -d "$_CONSOL_VIEW/.claude/shell-snapshots" ] && rm -rf "$_CONSOL_VIEW/.claude/shell-snapshots" 2>/dev/null || true
    [ -d "$_CONSOL_VIEW/.claude/telemetry" ] && rm -rf "$_CONSOL_VIEW/.claude/telemetry" 2>/dev/null || true

    # WP #1078 one-shot cleanup: detect orphan ".<name>_<N>" top-level dirs that
    # contain SQLite .db files — these are stranded artifacts of the broken
    # mksquashfs-append protocol (v2026.05.18.06 → v2026.05.23.19), unreachable
    # by any agent. Exclude them from the next consolidated layer via -e so the
    # consolidated tree finally matches the canonical layout. They live in the
    # squashfs layers (not in UPPER), so we can't rm them from $_CONSOL_VIEW —
    # we exclude them from mksquashfs's read of the merged view instead.
    # Match e.g. ".copilot_1", ".local_2", ".codex_3"; the trailing _<digit>+
    # signature is mksquashfs's collision-rename marker.
    for _orphan_dir in "$_CONSOL_VIEW"/.*_[0-9]*; do
        [ -d "$_orphan_dir" ] || continue
        _orphan_name=$(basename "$_orphan_dir")
        # Pattern guard: only ".<letters>_<digits>" — leave any user dir with
        # an underscore-number suffix that does NOT start with "." alone.
        case "$_orphan_name" in
            .*_[0-9]*) ;;
            *) continue ;;
        esac
        # Signature confirmation: must contain a .db file or be an empty
        # parent of one (the consolidated layer on Tower has empty .local_1/
        # because earlier append-failure cleanups rm'd the wide-bake-only deltas). Both
        # cases are safe to exclude — empty shadow dirs are pure noise; .db-
        # bearing shadow dirs are unreachable data the user lost weeks ago.
        if find "$_orphan_dir" -maxdepth 8 -name '*.db' -type f 2>/dev/null | grep -q . \
           || [ -z "$(find "$_orphan_dir" -mindepth 1 -type f -print -quit 2>/dev/null)" ]; then
            log "WP #1078 cleanup: excluding orphan shadow dir from consolidate: $_orphan_name"
            ORPHAN_N_EXCLUDES="$ORPHAN_N_EXCLUDES -e $_orphan_name"
            lifecycle_log "info" "consolidate_layers" "wp1078_orphan_excluded" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"orphan\":\"$_orphan_name\"}" 2>/dev/null || true
        fi
    done
fi

# Check disk space in /tmp for the scratch verify mount used by atomic_write_layer (minimal — just a mountpoint)
check_disk_space "/tmp/unraid-aicliagents/" 10 || { error "Insufficient disk space in /tmp for verification scratch mount"; update_task_status "Failed" 0 "Low disk space"; exit 1; }

# WP #271 guard: detect legacy nested 'home/' directory inside the home mount.
# Some early plugin versions packaged user-home contents at sqsh-root/home/...
# instead of sqsh-root/... directly. If left in place, mksquashfs re-packages
# this nesting on every consolidate, and ConfigService (which reads from the
# correct shallower path) silently sees stale/empty data. We detect early so
# the user does not lose their workspaces / chat history to a silent re-pack.
if [ "$TYPE" == "home" ] && [ -d "$_CONSOL_VIEW/home" ]; then
    log "Detected legacy nested 'home/' directory at $_CONSOL_VIEW/home"
    if [ -z "$(ls -A "$_CONSOL_VIEW/home" 2>/dev/null)" ]; then
        log "Legacy 'home/' is empty — removing before bake"
        rmdir "$_CONSOL_VIEW/home" 2>/dev/null || true
    else
        # Non-empty: refuse rather than silently shuffle data. Show a sample of
        # the offending files so the user can manually merge if needed.
        SAMPLE=$(find "$_CONSOL_VIEW/home" -type f 2>/dev/null | head -5 | tr '\n' ',' | sed 's/,$//')
        error "Refusing to consolidate: legacy nested home/ contains files (e.g. $SAMPLE)."
        error "Manual cleanup required — move data up one level into $_CONSOL_VIEW/ then rmdir $_CONSOL_VIEW/home."
        update_task_status "Failed" 0 "Legacy nested home/ artifact detected — manual merge required"
        exit 1
    fi
fi

# 3. Bake Consolidated Volume (Phase 2 — atomic, closing findings D-partial and E)
#
# Step 3a: Snapshot existing layers BEFORE bake so we know exactly which files to remove
#          after the new consolidated volume lands. This is the manifest-driven explicit
#          cleanup that replaces the wildcard find-delete (finding D).
log "Baking consolidated volume (atomic)..."
_consol_phase "writing"
update_task_status "Baking consolidated volume..." 40 ""

# Snapshot pre-bake layer list. nullglob ensures empty array when no layers present.
mapfile -t OLD_LAYERS < <(layer_files_of "$PERSIST_PATH" "$TYPE" "$ID")   # R-002 (#580): exact entity only

# Record a marker BEFORE bake. Any writes to UPPER_DIR after this marker
# won't be included in the baked volume — if we wipe the upper afterwards,
# those writes are lost. Same protection pattern as commit_stack.sh.
# This was the cause of a silent resume-file loss when gracefulClose ran
# concurrently with auto-consolidation.
CONSOLIDATE_MARKER="/tmp/unraid-aicliagents/.consolidate_marker_${TYPE}_${ID}"
touch "$CONSOLIDATE_MARKER"
# M2 fix (v2026.05.18.08): 50ms gap separates marker mtime from any concurrent
# write hitting the same tmpfs nanosecond — the post-bake UPPER_CHANGED check
# uses `find -newer $CONSOLIDATE_MARKER` and would otherwise miss equality writes.
sleep 0.05

# Check disk space on persistence target (atomic_write_layer writes directly there, not /tmp)
check_disk_space "$PERSIST_PATH/.diskcheck" 200 || {
    error "Insufficient disk space on persistence path for consolidated volume"
    update_task_status "Failed" 0 "Low disk space on Flash"
    rm -f "$CONSOLIDATE_MARKER"
    exit 1
}
# R-021 (#599): the fixed 200 MB above is no estimate. The consolidated layer is
# at most the old layers plus the writable layer (worst case: no overlap, no
# compression of the upper). Defer with no_space before mksquashfs starts.
_EST_UPPER_BYTES="$(_dir_bytes "$UPPER_DIR")"
_EST_BYTES=$(( $(_layer_total_bytes "${OLD_LAYERS[@]}") + _EST_UPPER_BYTES ))
# F14 (#599): when the worst case does not fit, count the writable layer at the
# compression ratio of the newest layer (the old layers are already compressed).
if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
    _EST_BYTES=$(( $(_layer_total_bytes "${OLD_LAYERS[@]}") + $(_layer_realistic_estimate "$PERSIST_PATH" "$TYPE" "$ID" "$_EST_UPPER_BYTES") ))
fi
if ! _layer_fits_space "$PERSIST_PATH" "$_EST_BYTES"; then
    error "Not enough boot disk space to merge the layers of $ID: the consolidated layer on $PERSIST_PATH needs about ${_FIT_NEED_MB} MB (estimate plus margin), ${_FIT_FREE_MB} MB free — consolidate not started."
    write_no_space_note "$TYPE" "$ID" "${_FIT_NEED_MB:-0}" "${_FIT_FREE_MB:-unknown}"
    update_task_status "Deferred" 0 "Not enough free space on Flash"
    rm -f "$CONSOLIDATE_MARKER"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_no_space" "no_space" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"need_mb\":$_FIT_NEED_MB,\"free_mb\":\"$_FIT_FREE_MB\",\"estimate_bytes\":$_EST_BYTES}" "warn"
fi

# S-09 (#1352): FAT32 per-file cap preflight (consolidate projects from the MERGED
# view, since the consolidated layer captures the whole home). vfat-only, fstype
# from findmnt — never path-derived. Within 5% of the 4 GiB cap → exit 4
# (precondition failed) + fat32_size_cap marker + dynamix notify, BEFORE the
# (expensive) mksquashfs and before anything destructive. The post-bake 3.9 GB
# size check further down stays as the belt-and-braces backstop.
if ! _fat32_cap_check "$PERSIST_PATH" "$_CONSOL_VIEW"; then
    error "Projected consolidated size (${_FAT32_PROJECTED_BYTES:-0} bytes) is within 5% of the FAT32 4 GiB per-file cap — refusing consolidate (precondition)."
    update_task_status "Failed" 0 "Exceeds FAT32 file size limit"
    rm -f "$CONSOLIDATE_MARKER"
    _fat32_cap_refuse "$TYPE" "$ID" "consolidate_layers"
    exit 4
fi

# WP #935: detect SQLite DBs in the merged view and back them up via Online
# Backup API before the bake.
# WP #1078 (2026-05-24): the bake now uses a no-mount pseudo-file merge
# (lower=_CONSOL_VIEW, -pf=sqlite_stage) → single-pass mksquashfs. The previous
# two-pass append protocol was broken — mksquashfs append renamed colliding
# top-level dirs with _N suffix, stranding the SQLite backups at unreachable
# paths. See docs/specs/SQLITE_APPEND_DATALOSS_BUG.md and commit_stack.sh for
# the detailed analysis. The .db sidecars (-wal/-shm/-journal) are excluded;
# SQLite reconstitutes them from the .db on next open.
#
# Forgejo #236: do not mount a second overlay over the live merged home here.
# That shape can pin the home overlay's superblock after a lazy detach and
# poison the next remount. bake_via_pseudofile_merge passes the live source
# directly to mksquashfs and injects each verified backup at its canonical path.
SQLITE_DBS=$(detect_sqlite_dbs "$_CONSOL_VIEW" 2>/dev/null)
# Count non-empty lines via awk (handles empty input cleanly; the prior
# `echo | grep -c -v '^$' || echo 0` produced "0\n0" on empty input and
# tripped `[: integer expected` further down).
SQLITE_DB_COUNT=$(printf '%s\n' "$SQLITE_DBS" | awk 'NF{c++}END{print c+0}')
SQLITE_STAGE=""
# Forgejo #250 follow-up: sqlite3's read connection can create/update the
# transient WAL-index `DB-shm` beside an otherwise idle WAL-mode source.  The
# backup excludes SHM from the layer and SQLite reconstructs it, so that exact
# relative path is not a durable concurrent write.  Keep a newline-delimited
# allow-list for the post-bake UPPER_CHANGED probe.  A WAL path is tracked
# separately and ignored only while empty; DB/non-empty-WAL/journal changes
# carry durable state and preserve the whole upper.
SQLITE_TRANSIENT_SHM_RELS=""
SQLITE_EMPTY_WAL_RELS=""
if [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    while IFS= read -r _sqlite_db; do
        [ -n "$_sqlite_db" ] || continue
        _sqlite_rel="${_sqlite_db#$_CONSOL_VIEW/}"
        if [ -n "$SQLITE_TRANSIENT_SHM_RELS" ]; then
            SQLITE_TRANSIENT_SHM_RELS="$SQLITE_TRANSIENT_SHM_RELS
${_sqlite_rel}-shm"
            SQLITE_EMPTY_WAL_RELS="$SQLITE_EMPTY_WAL_RELS
${_sqlite_rel}-wal"
        else
            SQLITE_TRANSIENT_SHM_RELS="${_sqlite_rel}-shm"
            SQLITE_EMPTY_WAL_RELS="${_sqlite_rel}-wal"
        fi
    done <<< "$SQLITE_DBS"
fi

# #374 (HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the SQLite stage folder"): the
# same stage rules as op_bake. The stage folder is on the file system of the
# live writable layer, it is removed on every exit (the EXIT hook; TERM/INT/HUP
# become an exit), and the stage folders of ended processes are swept first.
_SQLITE_STAGE_ROOT="$(sqlite_stage_root "$_CONSOL_BASE_UPPER")"
_consol_scratch_cleanup() {
    if [ -n "${SQLITE_STAGE:-}" ]; then rm -rf -- "$SQLITE_STAGE" 2>/dev/null || true; fi
    return 0
}
aicli_on_exit _consol_scratch_cleanup
trap 'exit 143' TERM
trap 'exit 130' INT
trap 'exit 129' HUP
_SWEPT="$(sqlite_stage_sweep_orphans "$_SQLITE_STAGE_ROOT" "$AICLI_SQLITE_STAGE_LEGACY_ROOT" "$(consolidate_stage_fallback_root)")"
if [ -n "$_SWEPT" ]; then
    log "Removed stage folder(s) of ended processes: $(printf '%s' "$_SWEPT" | tr '\n' ' ')"
fi

if [ "$SQLITE_DB_COUNT" -gt 0 ]; then
    log "Detected $SQLITE_DB_COUNT SQLite DB(s) in merged view — backing up via Online Backup API"
    update_task_status "Backing up SQLite DBs..." 35 ""
    _consol_phase "backing-up"
    # shellcheck disable=SC2086 — intentional word-splitting on the path list
    # docs/specs/CONSOLIDATE_STAGE_FALLBACK_LOCATION.md: when the default stage
    # folder has no room, a configured pool/array folder (never RAM, never the
    # flash) takes the copies instead.
    _STAGE_DEFAULT_ROOT="$_SQLITE_STAGE_ROOT"
    if ! sqlite_stage_choose_root $SQLITE_DBS; then
        rm -f "$CONSOLIDATE_MARKER"
        error "Not enough room to stage $SQLITE_DB_COUNT SQLite backup(s) in $_SQLITE_STAGE_ROOT: need about ${STAGE_NEED_MB} MB, ${STAGE_FREE_MB} MB free. Nothing was changed."
        update_task_status "Deferred" 0 "Not enough free space to copy the databases"
        _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "no_space" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_stage_no_space\",\"db_count\":$SQLITE_DB_COUNT,\"need_mb\":$STAGE_NEED_MB,\"free_mb\":\"$STAGE_FREE_MB\",\"stage_root\":\"$_SQLITE_STAGE_ROOT\",\"fallback_configured\":$([ -n "$(consolidate_stage_fallback_root)" ] && echo true || echo false)}" "warn"
    fi
    if [ "${STAGE_FALLBACK_USED:-0}" = "1" ]; then
        log "SQLite stage: $_STAGE_DEFAULT_ROOT has no room (need ${STAGE_NEED_MB} MB) — staging in $_SQLITE_STAGE_ROOT instead"
        lifecycle_log "info" "consolidate_layers" "consolidate_stage_fallback_used" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"default_root\":\"$_STAGE_DEFAULT_ROOT\",\"stage_root\":\"$_SQLITE_STAGE_ROOT\"}" 2>/dev/null || true
    fi
    SQLITE_STAGE="$(sqlite_stage_path "$_SQLITE_STAGE_ROOT" "consol" "$TYPE" "$ID" "${BASHPID:-$$}")"
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    if ! mkdir -p "$SQLITE_STAGE" 2>/dev/null; then
        rm -f "$CONSOLIDATE_MARKER"
        error "Could not create the SQLite stage folder $SQLITE_STAGE — failing"
        update_task_status "Failed" 0 "SQLite stage folder"
        exit 1
    fi

    # #374: never a bare call (set -e stopped the op here with no cleanup).
    _sba_rc=0
    # shellcheck disable=SC2086
    sqlite_backup_all "$_CONSOL_VIEW" "$SQLITE_STAGE" $SQLITE_DBS || _sba_rc=$?
    if [ "$_sba_rc" -ne 0 ]; then
        log "SQLite backup of ${SQLITE_BACKUP_FAILED_DB:-a database} failed (rc=$_sba_rc): ${SQLITE_BACKUP_ERROR:-no message}"
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        # M3 fix (v2026.05.18.08): hard errors (return 1) must fail, not defer.
        if [ "$_sba_rc" -eq 1 ]; then
            error "SQLite backup hard error during consolidate — failing"
            update_task_status "Failed" 0 "SQLite backup hard error"
            lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_hard_error\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
            exit 1
        fi
        log "SQLite backup deferred (DB locked or backup timeout) — exiting 2 to retry"
        update_task_status "Deferred" 0 "SQLite DB locked — retry later"
        _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "sqlite_backup_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"sqlite_backup_failed\",\"db_count\":$SQLITE_DB_COUNT}"
    fi
fi

# Step 3b: Atomic bake. Two paths:
#   (a) SQLite DBs detected → no-mount pseudo-file merge (single pass; sqlite_stage
#       shadows the live DBs in _CONSOL_VIEW; WAL/SHM/journal excluded).
#   (b) No SQLite DBs → direct bake of _CONSOL_VIEW.
# Both go through atomic_write_layer (tempfile + fsync + verify + atomic rename).
# WP #1078 orphan excludes (if any were detected in the pre-bake prune block)
# are folded into MKSQUASHFS_ARGS so both paths honour them.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"
if [ -n "$ORPHAN_N_EXCLUDES" ]; then
    export MKSQUASHFS_ARGS="${MKSQUASHFS_ARGS:-$_AWL_DEFAULT_ARGS} $ORPHAN_N_EXCLUDES"
fi

FINAL_NAME=""
# #490: atomic_write_layer writes the mksquashfs failure reason here, so the
# failure line below can name it (its stderr is lost inside $(...)).
export AICLI_AWL_REASON_FILE="$(mktemp "${TMPDIR:-/tmp}/aicli-awl-reason.XXXXXX" 2>/dev/null || true)"
_awl_reason_text() { [ -n "${AICLI_AWL_REASON_FILE:-}" ] && [ -s "$AICLI_AWL_REASON_FILE" ] && head -c2200 "$AICLI_AWL_REASON_FILE" | tr '\n' ' '; rm -f "${AICLI_AWL_REASON_FILE:-}" 2>/dev/null; return 0; }
if [ "$SQLITE_DB_COUNT" -gt 0 ] && [ -n "$SQLITE_STAGE" ] && [ -d "$SQLITE_STAGE" ]; then
    log "Consolidating via no-mount pseudo-file merge ($SQLITE_DB_COUNT SQLite backup(s) shadow live DBs)..."
    _consol_phase "writing"
    update_task_status "Baking consolidated volume..." 40 ""
    # shellcheck disable=SC2086 — intentional word-splitting on the DB path list
    if ! FINAL_NAME=$(bake_via_pseudofile_merge "$TYPE" "$ID" "$PERSIST_PATH" "$_CONSOL_VIEW" "$SQLITE_STAGE" "consolidated" $SQLITE_DBS); then
        error "No-mount SQLite consolidation bake failed. $(_awl_reason_text)"
        update_task_status "Failed" 0 "Bake failed"
        rm -rf "$SQLITE_STAGE" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"pseudofile_merge_bake_failed\"}" 2>/dev/null || true
        exit 1
    fi
    rm -rf "$SQLITE_STAGE" 2>/dev/null
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_sqlite_merged" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"sqsh\":\"$FINAL_NAME\",\"db_count\":$SQLITE_DB_COUNT}" 2>/dev/null || true
else
    _consol_phase "writing"
    update_task_status "Baking consolidated volume..." 40 ""
    if ! FINAL_NAME=$(atomic_write_layer "$TYPE" "$ID" "$PERSIST_PATH" "$_CONSOL_VIEW" "consolidated"); then
        error "Atomic consolidation bake failed. $(_awl_reason_text)"
        update_task_status "Failed" 0 "Bake failed"
        rm -f "$CONSOLIDATE_MARKER"
        lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"atomic_write_layer_failed\"}" 2>/dev/null || true
        exit 1
    fi
fi

log "Consolidated volume written: $FINAL_NAME"
_consol_phase "finalizing"
update_task_status "Finalizing volume..." 80 ""

# Sanity: check FAT32 size limit on the final file. #495: only on a vfat persist
# folder (the same rule as the _fat32_cap_check preflight). On a ZFS or btrfs
# boot disk a merged layer over 3.9 GB is valid and was thrown away.
rm -f "${AICLI_AWL_REASON_FILE:-}" 2>/dev/null
SQSH_SIZE=$(stat -c%s "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
MAX_SIZE=$((3900 * 1024 * 1024)) # 3.9GB
_CONSOL_PERSIST_FST="${AICLI_ITEST_PERSIST_FSTYPE:-$(findmnt --noheadings --output FSTYPE --target "$PERSIST_PATH" 2>/dev/null || echo '')}"
if [ "$_CONSOL_PERSIST_FST" = "vfat" ] && [ "$SQSH_SIZE" -gt "$MAX_SIZE" ]; then
    error "Consolidated volume exceeds 3.9GB FAT32 limit ($SQSH_SIZE bytes). Removing and keeping old layers."
    update_task_status "Failed" 0 "Exceeds FAT32 limit"
    rm -f "$PERSIST_PATH/$FINAL_NAME"
    rm -f "$CONSOLIDATE_MARKER"
    exit 1
fi

# Forgejo #296 (2026-09-23): the bake ran without the mount lock, so a launch
# could have switched the generation while mksquashfs read the view. Check the
# binding again, then refuse a layer that is no bigger than an empty squashfs
# while the writable layer holds data. In both cases remove ONLY the new
# layer; the older layers and the writable layer stay as they are.
_consol_recheck_binding "after_bake" "$PERSIST_PATH/$FINAL_NAME"
if _layer_near_empty "$SQSH_SIZE" "$UPPER_DIR" "$PERSIST_PATH/$FINAL_NAME"; then
    error "Forgejo #296: consolidated layer $FINAL_NAME is $SQSH_SIZE bytes and lists no files, but the writable layer $UPPER_DIR holds data. Refusing it — older layers and the writable layer are kept. Deferring."
    rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Deferred" 0 "Consolidated layer was empty — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"near_empty_layer\",\"bytes\":$SQSH_SIZE}" "warn"
fi
# 2026-09-29 (forum report): the same refusal against the VIEW the bake read.
# After an install the writable layer can be empty (everything sits in the
# read-only layers), so the check above sees nothing to protect. A layer that
# lists no file while the view shows one would replace every real layer. Also
# refuse a layer read from a view that is no longer the live one.
_CONSOL_VIEW_NOW="$(_consol_view_dir "$TYPE" "$ID" "$MNT_POINT")"
if [ "$_CONSOL_VIEW_NOW" != "$_CONSOL_VIEW" ] \
   || _layer_near_empty "$SQSH_SIZE" "$_CONSOL_VIEW" "$PERSIST_PATH/$FINAL_NAME"; then
    error "Consolidated layer $FINAL_NAME ($SQSH_SIZE bytes) does not hold the view it was read from ($_CONSOL_VIEW, live now: $_CONSOL_VIEW_NOW). Refusing it — the older layers and the writable layer are kept. Deferring."
    rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Deferred" 0 "Consolidated layer was empty — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "consolidate_lowerdir_incomplete" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"layer_misses_view\",\"bytes\":$SQSH_SIZE}" "warn"
fi

# --- CRITICAL SECTION: take the shared per-entity storage lock ---------------
# Race fix: the supervisor's reconcile runs every ~7s and scans this directory.
# Between the manifest replace and the end of the old-layer delete loop below,
# the on-disk layer set legitimately differs from the manifest. If a reconcile
# tick lands in that window it mis-classifies the old layers as "untracked" and
# quarantines them to .untracked/ — actively losing baked data. The fix: hold
# the SAME per-entity lock commit_stack.sh uses, for the whole swap; the
# supervisor's reconcile skips any entity whose lock is held.
#
# The lock is taken HERE — after the (long) mksquashfs, which ran unlocked so it
# never blocks a bake. If a bake holds the lock right now, defer (exit 2).
_CONSOL_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
_CONSOL_LOCK="/var/run/aicli-bake-${TYPE}-${_CONSOL_LOCK_ID}.lock"
exec 8>"$_CONSOL_LOCK"
if ! flock -n 8; then
    _cons_holder="unknown"
    if declare -f lock_holder_describe >/dev/null 2>&1; then
        _cons_holder="$(lock_holder_describe "$_CONSOL_LOCK" 2>/dev/null)" || _cons_holder="unknown"
        [ -n "$_cons_holder" ] || _cons_holder="unknown"
    fi
    error "Per-entity storage lock held (a bake is in flight; holder: $_cons_holder) — deferring consolidate."
    lifecycle_log "warn" "consolidate" "consolidate_deferred_locked" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"holder\":\"$_cons_holder\"}" 2>/dev/null || true
    rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Deferred" 0 "Bake in progress — retry later"
    _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "bake_lock_held"
fi

# Re-validate: did a bake land a NEW delta since OLD_LAYERS was snapshotted
# (before the unlocked mksquashfs)? If so, the consolidated layer just built is
# already stale — it cannot contain that delta. Discard it (non-destructive —
# nothing else has been touched yet) and defer. The bake always wins.
mapfile -t _CURRENT_LAYERS < <(layer_files_of "$PERSIST_PATH" "$TYPE" "$ID")   # R-002 (#580): exact entity only
for _cur in "${_CURRENT_LAYERS[@]}"; do
    _cur_base="$(basename "$_cur")"
    [ "$_cur_base" = "$FINAL_NAME" ] && continue
    _was_known=0
    for _old in "${OLD_LAYERS[@]}"; do
        [ "$(basename "$_old")" = "$_cur_base" ] && _was_known=1 && break
    done
    if [ "$_was_known" -eq 0 ]; then
        error "New layer '$_cur_base' appeared during consolidation — a bake landed. Discarding stale consolidated layer; deferring."
        rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
        rm -f "$CONSOLIDATE_MARKER"
        update_task_status "Deferred" 0 "A bake completed during consolidation — retry later"
        _op_defer "$TYPE" "$ID" "consolidate_layers" "bash_consolidate_deferred" "bake_landed_during_consolidate" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"bake_landed_during_consolidate\",\"new_layer\":\"$_cur_base\"}"
    fi
done
# Lock held on fd 8 + layer set validated — safe to swap the manifest and
# remove the old layers. Lock releases when this script exits.

# 4 (was 5). Update manifest BEFORE deleting old layer files (Fix #4a).
#
# Order matters for crash safety:
#   • manifest update first → if killed between manifest write and file deletes, the old
#     files linger on disk UNTRACKED. Reconcile finds them as "untracked" → recovers them
#     harmlessly (mounts RO, sample-reads, re-adds to manifest as 'recovered').
#   • file deletes first (old order) → if killed between deletes and manifest write, the
#     manifest still lists now-deleted layers → reconcile finds them as "missing" → halts
#     the entity with corrupt_layers. This is what caused the test-box corruption.
#
# The manifest is written atomically (tmp+fsync+rename inside LayerManifestService::replaceLayers).
log "Updating manifest to list only the new consolidated layer (before file deletes)..."
update_task_status "Updating manifest..." 85 ""
# F6 (WP#1331): the SINGLE manifest writer (sha256/bytes/kind computed PHP-side).
if manifest_replace_layers "$TYPE" "$ID" "$PERSIST_PATH" "$FINAL_NAME"; then
    lifecycle_log "info" "consolidate_layers" "manifest_updated_before_delete" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\"}" 2>/dev/null || true
else
    # R-016 (#594): the old layers are still on disk and the manifest still names
    # them. Deleting them now would leave the manifest naming deleted layers, the
    # state the order of steps exists to prevent. Drop the new layer and stop.
    error "Consolidate $TYPE/$ID: the manifest could not be updated to list $FINAL_NAME — the new layer is removed and the old layers stay. Nothing was deleted."
    lifecycle_log "error" "consolidate_layers" "consolidate_manifest_update_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\"}" 2>/dev/null || true
    rm -f "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Consolidation failed: the layer list could not be updated." 100 ""
    exit 1
fi

# L3.5 (Follow-on 4): deterministic SIGKILL window — manifest replaced to
# [consolidated], NO intent yet, old layers still on disk.
_itest_sigkill_window "after_manifest_replace"

# 4b. Delete old layer files — now safe because manifest no longer references them.
#     If SIGTERM fires here, the old files are untracked (reconcile recovers),
#     not missing (reconcile halts).
log "Cleaning up old layers..."
_consol_phase "cleaning"
update_task_status "Cleaning up old layers..." 90 ""
guard_path "$PERSIST_PATH" "PERSIST_PATH (cleanup)" || { error "Path guard failed before cleanup"; exit 1; }

# Epic #1310 #1320: write a write-ahead INTENT before the destructive delete, so
# an interrupted consolidate is unambiguous — the listed deletes are INTENTIONAL
# prunes (the kept consolidated layer holds their data), never real loss. Cleared
# once the deletes complete. (write_intent/clear_intent from common.sh.)
_INTENT_DEL=""
for _ol in "${OLD_LAYERS[@]}"; do
    _olb="$(basename "$_ol")"
    [ "$_olb" = "$FINAL_NAME" ] && continue
    [ -n "$_INTENT_DEL" ] && _INTENT_DEL="$_INTENT_DEL,"
    _INTENT_DEL="$_INTENT_DEL\"$_olb\""
done
if ! write_intent "$PERSIST_PATH" "$TYPE" "$ID" "{\"op\":\"consolidate\",\"keep\":\"$FINAL_NAME\",\"delete\":[$_INTENT_DEL]}"; then
    # R-020 (#598): no valid plan on disk, so no layer may be deleted. The manifest
    # lists only $FINAL_NAME; the old layers stay as untracked files that reconcile
    # recovers harmlessly.
    error "Consolidate $TYPE/$ID: the delete plan could not be written (disk full?) — the old layers are kept."
    lifecycle_log "error" "consolidate_layers" "consolidate_intent_write_failed" "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
    rm -f "$CONSOLIDATE_MARKER"
    update_task_status "Consolidation failed: the delete plan could not be written." 100 ""
    exit 1
fi

# L3.5 (Follow-on 4): intent written, deletes not yet started.
_itest_sigkill_window "after_intent"

for old_layer in "${OLD_LAYERS[@]}"; do
    [ -f "$old_layer" ] || continue
    # Belt-and-braces: never delete the new consolidated file
    [ "$(basename "$old_layer")" = "$FINAL_NAME" ] && continue
    # GitHub #13: unmount this layer's loop mount BEFORE removing its backing
    # file. The manifest already points only at $FINAL_NAME, and this consolidate
    # rebuilds the overlay from a different lower set, so nothing will ever
    # remount this squashfs again. Left mounted, its deleted-backing-file loop
    # looks like the benign "deleted-but-open" case the supervisor's reaper
    # skips for a bake (Feature #1382) — but here it is a permanent orphan.
    # #512: a REAL unmount only, and the file goes only when no loop still
    # reads it. The old `umount || umount -l` then `rm -f` hid a held loop:
    # the deleted layer kept its space on the flash until a reboot. A held
    # layer is parked as .pending-delete.<name> for the idle sweep.
    _old_layer_mnt="/tmp/unraid-aicliagents/mnt/$(basename "$old_layer" .sqsh)"
    if layer_retire_file "$old_layer" "$_old_layer_mnt"; then
        lifecycle_log "info" "consolidate_layers" "old_layer_removed" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"file\":\"$(basename "$old_layer")\"}" 2>/dev/null || true
    else
        lifecycle_log "warn" "consolidate_layers" "old_layer_delete_pending" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"file\":\"$(basename "$old_layer")\"}" 2>/dev/null || true
    fi
    log "Retired old layer: $(basename "$old_layer")"
    # L3.5 (Follow-on 4): mid-delete — at least one old layer pruned, intent present,
    # others still on disk. (With >=2 old layers the kill lands here.)
    _itest_sigkill_window "mid_delete"
done
# L3.5 (Follow-on 4): all deletes done, intent still present (stale).
_itest_sigkill_window "after_delete"
# Deletes complete — the consolidated layer is the sole authority now; clear the intent.
clear_intent "$PERSIST_PATH" "$TYPE" "$ID"

# 6. Remount & reclaim the writable upper
# WP #1080 + #1081 (2026-05-24): single fuser check, then check UPPER_CHANGED
# BEFORE the umount (find -newer reads $UPPER_DIR directly, doesn't need the
# mount), then refresh-and-wipe. The prior structure had umount → check →
# wipe → mount, leaving the mount torn down for the entire duration of the
# wipe (potentially seconds for big uppers). The new structure narrows the
# umount window to the mount_stack.sh refresh only.
#
# Why check UPPER_CHANGED before umount: `find $UPPER_DIR -newer $marker`
# operates on the raw ZRAM tmpfs upper, not through the overlay mount.
# Doing the check before umount means we don't add to the unmounted window.
# Forgejo #250: hold the same mount-operation lock used by every launch and
# op_mount across the WHOLE unmount -> upper swap -> remount transaction.  The
# old code tried to rename UPPER_DIR while it was still attached to the live
# overlay; Linux refuses that rename on this host.  The failure was collapsed
# to an empty _SWAPPED_UPPER, op_mount rebound the original 1.1 GB upper, and
# consolidation nevertheless reported success.  Taking the lock first closes
# the launch race; the second idle check below then makes a real unmount safe.
{ exec {_CONSOLIDATE_MOUNT_LOCK_FD}>"$(mount_op_lock_path "$TYPE" "$ID")"; } 2>/dev/null || _CONSOLIDATE_MOUNT_LOCK_FD=""
if [ -z "$_CONSOLIDATE_MOUNT_LOCK_FD" ] || ! flock -w 30 "$_CONSOLIDATE_MOUNT_LOCK_FD"; then
    log "Consolidation is durable, but the mount-operation lock could not be acquired — preserving the writable upper for a later reclaim."
    rm -f "$CONSOLIDATE_MARKER"
    FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
    lifecycle_log "warn" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"mount_lock_timeout\"}" 2>/dev/null || true
    update_task_status "Consolidation complete (writable-layer reclaim deferred)." 100 ""
    exit 0
fi

if _consol_mount_busy "$TYPE" "$ID" "$MNT_POINT"; then
    log "Mount became BUSY during consolidate (agent launched / live session). Deferring refresh+wipe — next mount cycle picks up $FINAL_NAME."
    rm -f "$CONSOLIDATE_MARKER"
    FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"mount_busy_at_refresh\"}" 2>/dev/null || true
    update_task_status "Consolidation complete (mount refresh deferred — sessions active)." 100 ""
    exit 0
fi

# Check UPPER_CHANGED before the umount window. find -newer operates on the
# raw ZRAM tmpfs, not through the overlay mount, so this is safe to do here.
# (D-353: skip the wipe if any writes arrived after the bake marker — those
# writes are NOT in the consolidated volume and would be silently lost.)
UPPER_CHANGED=""
if [ -f "$CONSOLIDATE_MARKER" ] && [ -d "$UPPER_DIR" ]; then
    # R-018 (#596): every kind of entry counts (symlink, empty folder, whiteout),
    # not only regular files. The helper ignores only the SQLite self-writes the
    # consolidator makes (a detected DB's `-shm`, a zero-byte `-wal`): sqlite3
    # .backup itself can leave those reconstructable artefacts. Any DB,
    # non-empty WAL, journal, or unrelated write remains a real safety stop.
    UPPER_CHANGED="$(upper_first_changed_entry "$UPPER_DIR" "$CONSOLIDATE_MARKER" || true)"
fi
# Forgejo #442: the marker is removed AFTER the swap block below, which needs it
# to tell the entries that changed during the bake from the ones the base holds.

log "Finalizing stack and reclaiming the writable upper..."
log "Note: Active agent terminals may log transient I/O errors during remount. This is expected and resolves automatically."
update_task_status "Finalizing stack..." 95 ""

# GitHub #16: swap the upper aside BEFORE the remount, instead of wiping it
# in place AFTER (the old order below this comment, kept only in history).
# The old code called op_mount first, then ran `find $UPPER_DIR -delete`
# against the directory that mount had JUST attached as the live overlay
# upperdir — mutating a mounted overlay's upperdir from underneath it is the
    # same undefined-behavior hazard documented for the removed overlay-on-live
    # merge path (Forgejo #236)
# elsewhere in this codebase (GitHub #9), and in practice it stranded files:
# present in the baked lower, absent from upper, no whiteout, ENOENT through
# the merged view. swap_dir_for_reclaim (common.sh) renames $UPPER_DIR aside
# and creates a fresh EMPTY directory at the same path — both BEFORE op_mount
# ever touches it — so the remount's upper was never the directory we are
# about to delete.
_SWAPPED_UPPER=""
_UPPER_KEEP_FAILED=0
# Forgejo #442 (docs/specs/CONSOLIDATE_PARTIAL_UPPER_RECLAIM.md): the swap runs
# whether or not something wrote during the bake. Writes during the bake used to
# keep the WHOLE upper; now only the entries newer than the marker are put back
# into the fresh upper (upper_keep_changed_entries), and everything the new base
# already holds is reclaimed.
if :; then
    # A mounted overlay pins its upperdir and makes the rename fail.  Release it
    # cleanly while the mount-operation lock prevents a concurrent launch, then
    # swap the now-detached directory aside before op_mount sees it again.
    if _mount_teardown_arbiter "$MNT_POINT"; then
        _PRE_SWAP_UNMOUNT_RC=0
    else
        _PRE_SWAP_UNMOUNT_RC=$?
    fi
    if [ "$_PRE_SWAP_UNMOUNT_RC" -ne 0 ]; then
        log "Consolidation is durable, but the live overlay could not be released for writable-layer reclaim (rc=$_PRE_SWAP_UNMOUNT_RC)."
        FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
        lifecycle_log "warn" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"pre_swap_unmount_rc${_PRE_SWAP_UNMOUNT_RC}\"}" 2>/dev/null || true
        update_task_status "Consolidation complete (writable-layer reclaim deferred)." 100 ""
        exit 0
    fi
    _SWAPPED_UPPER="$(swap_dir_for_reclaim "$UPPER_DIR")" || _SWAPPED_UPPER=""
    if [ -z "$_SWAPPED_UPPER" ]; then
        # The old data remains at the canonical path.  Put the home back online,
        # but report the reclaim deferral explicitly instead of claiming that
        # the upper was cleared.
        if ! op_mount "$TYPE" "$ID" "$PERSIST_PATH" "" 1; then
            error "Writable-upper swap failed and the original upper could not be remounted — data remains at $UPPER_DIR, but the entity is offline."
            lifecycle_log "error" "consolidate_layers" "bash_consolidate_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"reason\":\"upper_swap_failed_remount_failed\"}" 2>/dev/null || true
            update_task_status "Consolidation saved the layer but could not remount the home." 100 ""
            exit 1
        fi
        log "Consolidation is durable, but the writable upper could not be swapped aside — preserving it for a later reclaim."
        FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
        lifecycle_log "warn" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"upper_swap_failed\"}" 2>/dev/null || true
        update_task_status "Consolidation complete (writable-layer reclaim deferred)." 100 ""
        exit 0
    fi
    if [ -n "$UPPER_CHANGED" ]; then
        # Something wrote during the bake. Put ONLY those entries (newer than
        # the marker, with their SQLite families) into the fresh upper. The base
        # just written holds everything else, so it is reclaimed with the old
        # upper below. If the copy cannot be proven complete, undo the swap and
        # keep the whole old upper — the pre-#442 behaviour, never a loss.
        if upper_keep_changed_entries "$_SWAPPED_UPPER" "$UPPER_DIR" "$CONSOLIDATE_MARKER"; then
            log "Writes during the merge: kept $KEEP_COUNT changed file(s) ($KEEP_BYTES bytes, e.g. $KEEP_EXAMPLE) in the writable layer; reclaiming $RECLAIM_COUNT file(s) ($RECLAIM_BYTES bytes) now held by $FINAL_NAME."
            lifecycle_log "info" "consolidate_layers" "consolidate_upper_partial_reclaim" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"kept_count\":$KEEP_COUNT,\"kept_bytes\":$KEEP_BYTES,\"reclaimed_count\":$RECLAIM_COUNT,\"reclaimed_bytes\":$RECLAIM_BYTES,\"example\":\"$KEEP_EXAMPLE\"}" 2>/dev/null || true
        else
            _UPPER_KEEP_RC=$?
            log "Could not copy the entries written during the merge into the fresh writable layer (rc=$_UPPER_KEEP_RC) — keeping the whole old layer instead."
            lifecycle_log "warn" "consolidate_layers" "consolidate_upper_keep_failed" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"rc\":$_UPPER_KEEP_RC,\"example\":\"$UPPER_CHANGED\"}" 2>/dev/null || true
            restore_swapped_dir "$UPPER_DIR" "$_SWAPPED_UPPER" || log "WARNING: could not restore the upper after a failed keep — data is in $_SWAPPED_UPPER"
            _SWAPPED_UPPER=""
            _UPPER_KEEP_FAILED=1
        fi
    fi
fi
rm -f "$CONSOLIDATE_MARKER"

# Refresh mount (mount_stack.sh handles its own umount-then-mount cycle — the
# brief umount window inside it is the only unmounted gap).
# WP #1309: capture the exit. If the mount went busy (or phantom) in the race
# after the fuser idle-check above, op_mount DEFERS (exit 2) instead of an
# unsafe lazy-remount. The consolidate itself already SUCCEEDED durably
# (manifest swapped, old layers removed, consolidated layer on Flash) — so on
# ANY non-zero refresh we preserve the upper, SKIP the wipe, and finish clean;
# the next idle mount cycle picks up the new lower and a later bake reclaims the
# upper. (Mirrors the "mount became busy" branch just above.)
if op_mount "$TYPE" "$ID" "$PERSIST_PATH" "" 1; then
    _FINAL_REFRESH_RC=0
else
    _FINAL_REFRESH_RC=$?
fi
if [ "$_FINAL_REFRESH_RC" -ne 0 ]; then
    # The remount never came up on the fresh upper — undo the swap so the
    # real data is back at the canonical path, exactly as the "upper
    # preserved, reclaim deferred" message below promises.
    if [ -n "$_SWAPPED_UPPER" ]; then
        restore_swapped_dir "$UPPER_DIR" "$_SWAPPED_UPPER" || log "WARNING: could not restore upper after deferred remount — data is in $_SWAPPED_UPPER"
    fi
    log "Post-consolidate mount refresh deferred (rc=$_FINAL_REFRESH_RC) — consolidation complete; upper preserved, reclaim deferred to idle."
    FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"refresh_deferred_rc${_FINAL_REFRESH_RC}\"}" 2>/dev/null || true
    update_task_status "Consolidation complete (mount refresh deferred — sessions active)." 100 ""
    exit 0
fi

# The new consolidated lower is exposed via the just-refreshed mount, which is
# now using the fresh empty upper — reclaim the renamed-aside copy of the old
# one in the background. WORK_DIR is never touched (WP #1081): under the
# refresh-then-reclaim order the overlay holds a live kernel fd on
# $WORK_DIR/work, and overlayfs clears workdir on its own mount anyway.
if [ -n "$_SWAPPED_UPPER" ]; then
    # #337: detached through the shared helper (no inherited descriptors).
    if ! bash "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../aicli-detach.sh" -- rm -rf "$_SWAPPED_UPPER" >/dev/null 2>&1; then
        ( rm -rf "$_SWAPPED_UPPER" ) </dev/null >/dev/null 2>&1 &
        disown 2>/dev/null || true
    fi
elif [ -n "$UPPER_CHANGED" ] && [ -d "$UPPER_DIR" ]; then
    # Only reached when upper_keep_changed_entries failed (#442) and the swap
    # was undone: the whole upper stays, the next commit deltas it.
    log "Writes detected in upper layer during bake (e.g. $UPPER_CHANGED) and the changed entries could not be kept on their own. Preserving upper to avoid data loss — next commit will delta them onto the consolidated base."
    sync
    FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
    lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok_refresh_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES,\"reason\":\"upper_changed_during_bake\"}" 2>/dev/null || true
    update_task_status "Consolidation complete (writable-layer reclaim deferred — writes detected)." 100 ""
    exit 0
fi
sync

FINAL_BYTES=$(stat -c '%s' "$PERSIST_PATH/$FINAL_NAME" 2>/dev/null || echo 0)
lifecycle_log "info" "consolidate_layers" "bash_consolidate_ok" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"final\":\"$FINAL_NAME\",\"bytes\":$FINAL_BYTES}" 2>/dev/null || true
update_task_status "Consolidation complete." 100 ""
)

# ---- op_graduate  (S-10, Feature #1354) --------------------------------------
# Migrate a flash (layering) entity to the PASSTHROUGH backend on a capable
# device: flush → consolidate to ONE layer → copy into the plain passthrough
# dir → verify → write-ahead intent → MOVE layers to .graduated/ (14-day
# retention, never deleted here) → manifest backend flip + expected-layers
# clear in ONE locked write → clear intent → rebind the mount via the
# passthrough path. Exit contract: 0 ok · 2 deferred (transient; reason
# marker) · 4 precondition failed (reason marker graduate_precondition) ·
# 1 hard failure.
#
# CRASH ARMS (both sides of the manifest write — THE authority flip):
#   • Killed BEFORE the manifest write: the layers are still authoritative.
#     Either they are still on disk (intent written, move not yet done →
#     classifier sees a healthy flash entity; the populated passthrough dir +
#     staging marker are inert garbage a retry converges over) or the single
#     consolidated layer was already renamed into .graduated/ (the intent's
#     "delete" plan marks it an intentional prune, so reconcile never halts;
#     the supervisor's graduate-intent recovery completes the flip forward on
#     the next tick because the verified passthrough copy is populated).
#   • Killed AFTER the manifest write (before clear_intent): passthrough is
#     authoritative — effective_backend un-pins via the recorded backend, the
#     next mount binds the plain dir, and .graduated/ holds the rollback copy.
#     The supervisor's recovery clears the stale intent.
op_graduate() (
set -euo pipefail
TYPE="${1:-}"
ID="${2:-}"
PERSIST_PATH="${3:-}"
FORCE_DIRECT="${4:-0}"

source "$_SO_DIR/common.sh"
install_failure_trap "$TYPE" "$ID" "graduate"
source "$_SO_DIR/resolve_paths.sh" 2>/dev/null || true
# manifest_set_backend — the single locked backend writer (manifest_write.sh).
source "$_SO_DIR/manifest_write.sh" 2>/dev/null || true

log() {
    local msg
    msg="[$(get_ts)] [INFO] [GRADUATE] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}
error() {
    local msg
    msg="[$(get_ts)] [ERR!] [GRADUATE] $(_trace_tag)$1"
    echo "$msg"
    echo "$msg" >> "$DEBUG_LOG"
}

if [ -z "$TYPE" ] || [ -z "$ID" ] || [ -z "$PERSIST_PATH" ]; then
    error "usage: op_graduate <home|agent> <id> <persist>"
    exit 1
fi

guard_path "$PERSIST_PATH" "PERSIST_PATH" || { error "Persistence path failed validation: $PERSIST_PATH"; exit 1; }
_assert_persist_durable "$PERSIST_PATH" || { error "Persistence path is on a non-durable filesystem — graduate refused"; exit 1; }

_GR_LOCK_ID="${ID//[^a-zA-Z0-9_-]/_}"
# Clear any stale defer-reason marker so the reason PHP reads is THIS run's truth.
rm -f "/tmp/unraid-aicliagents/.bake_defer_reason_${TYPE}_${_GR_LOCK_ID}" \
      "/tmp/unraid-aicliagents/.graduate_refusal_${TYPE}_${_GR_LOCK_ID}" 2>/dev/null || true

_entity_paths_live "$TYPE" "$ID" "$PERSIST_PATH"   # UPPER_DIR/WORK_DIR/MNT_POINT/ENTITY_UPPER_MODE  # Phase 3: the LIVE generation's layer, not the id-keyed one

# _gr_precondition_refuse <reason> — marker + lifecycle + exit 4 (precondition).
_gr_precondition_refuse() {
    local _why="$1" _path="${2:-}" _first="${3:-}"
    error "Graduate precondition failed: $_why${_path:+ ($_path${_first:+, first: $_first})}"
    write_defer_reason "$TYPE" "$ID" "graduate_precondition"
    # #577: say WHY in the tray (reason code, folder, first entry in the way).
    write_graduate_refusal "$TYPE" "$ID" "$_why" "$_path" "$_first"
    lifecycle_log "info" "graduate" "graduate_precondition_failed" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"why\":\"$_why\"}" 2>/dev/null || true
    exit 4
}

# ---- 1. Precondition (pure helper + live facts) ------------------------------
declare -f graduate_precondition_from_facts >/dev/null 2>&1 \
    || { error "detect_backend.sh helpers unavailable — refusing"; exit 1; }
_GR_HAS_LAYERS="$(_entity_has_layers "$PERSIST_PATH" "$TYPE" "$ID")"
_GR_EFFECTIVE="$(effective_backend "$TYPE" "$ID" "$PERSIST_PATH")"
if [ "$FORCE_DIRECT" = "1" ]; then
    # The global storage-policy migration is the one authorised caller that may
    # choose plain directories even when the device probe recommends layering
    # (for example a USB target after an explicit wear acknowledgement). It
    # still requires actual layers; a layer-free entity is already direct or is
    # handled by the backend-migrate wrapper as an empty plain entity.
    [ "$_GR_HAS_LAYERS" = "1" ] || _gr_precondition_refuse "no_layers_to_convert"
    log "Forced policy migration to passthrough (has_layers=$_GR_HAS_LAYERS)"
else
    _GR_DEVICE="${AICLI_ITEST_BACKEND:-$(backend_for "$PERSIST_PATH")}"
    _GR_ENGINE="$(probe_target "$PERSIST_PATH" 2>/dev/null | grep -oE '"engine":"[a-z]+"' | cut -d'"' -f4)"
    _GR_ENGINE="${_GR_ENGINE:-layering}"
    if _GR_WHY="$(graduate_precondition_from_facts "$_GR_ENGINE" "$_GR_DEVICE" "$_GR_EFFECTIVE" "$_GR_HAS_LAYERS")"; then
        log "Precondition ok (engine=$_GR_ENGINE device=$_GR_DEVICE effective=$_GR_EFFECTIVE has_layers=$_GR_HAS_LAYERS)"
    else
        _gr_precondition_refuse "$_GR_WHY"
    fi
fi

lifecycle_log "info" "graduate" "graduate_start" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"persist_path\":\"$PERSIST_PATH\"}" 2>/dev/null || true

# ---- HOME: convert to plain folders from the merged view (#496) -------------
# docs/specs/CONVERT_LAYERED_TO_PLAIN_FOLDERS.md. A home is NOT saved and
# merged first (the old steps 2-3 below, kept for agents): on a full boot disk
# that merge is the step that cannot fit. The merged view (every layer plus the
# writable layer) is copied as it is, with the home's sessions closed by the
# supervisor (reason user_convert). Two modes:
#   direct — the persist disk has room for the copy beside the layers: copy into
#            the plain folder, verify, retire the layers to .graduated/, flip.
#   staged — no room: copy to a work folder on another disk, verify, write the
#            intent, move the layers and the writable layer to the work folder
#            (this frees the boot disk), copy back, verify, flip. A failure after
#            the move puts everything back (R5); the supervisor finishes or rolls
#            back an interrupted run from the intent (R8).
# BOOT_DISK_SPACE.md R3: ONE layered agent becomes plain folders in the versioned
# layout (passthrough/agents/.versions/<id>/<gen> + the stable link). The supervisor
# sets AICLI_GRADUATE_REASON=user_convert for this run. The global policy migration
# uses this same copy-first flow so it checks space before it changes any layer.
# Both paths copy the merged view, verify, move the layers to .graduated/, flip, bind.
_GR_AGENT=0
if [ "$TYPE" = "agent" ] && { [ "${AICLI_GRADUATE_REASON:-}" = "user_convert" ] || [ "$FORCE_DIRECT" = "1" ]; }; then
    _GR_AGENT=1
    if [ "$(_keep_agents_compressed)" = "1" ]; then
        _gr_precondition_refuse "keep_agents_compressed"
    fi
fi
if [ "$TYPE" = "home" ] || [ "$_GR_AGENT" = "1" ]; then
    _gr_phase() {
        [ -n "${AICLI_JOB_ID:-}" ] || return 0
        if ! declare -f job_ledger_set_phase >/dev/null 2>&1; then
            # shellcheck disable=SC1091
            source "$_SO_DIR/../supervisor/queue_helpers.sh" 2>/dev/null || true
        fi
        declare -f job_ledger_set_phase >/dev/null 2>&1 || return 0
        job_ledger_set_phase "$AICLI_JOB_ID" "$1" 2>/dev/null || true
    }
    _gr_bytes() { case "${1:-}" in ''|*[!0-9]*) printf '0' ;; *) printf '%s' "$1" ;; esac; }
    # The plain reason the tray shows when the job fails (SupervisorService reads it).
    _GR_REASON_FILE="/tmp/unraid-aicliagents/.convert_reason_${TYPE}_${_GR_LOCK_ID}"
    rm -f "$_GR_REASON_FILE" 2>/dev/null || true
    _gr_reason() { printf '%s\n' "$1" > "$_GR_REASON_FILE" 2>/dev/null || true; }
    _gr_phase "checking"

    # #505: while this conversion runs, no other mount may build the old layered
    # view of this home (an on-demand mount from a page load did, mid-run, on
    # prod02 2026-10-14). op_mount refuses while this lock's process is alive or
    # a conversion intent is open; this run's own mount passes AICLI_CONVERT_SELF.
    _GR_CONVERT_LOCK="$(convert_lock_path "$TYPE" "$_GR_LOCK_ID")"
    if _gr_why="$(convert_mount_blocked "$PERSIST_PATH" "$TYPE" "$ID")"; then
        _gr_reason "Another conversion of this home is still open. Nothing was changed."
        error "Conversion refused: $_gr_why."
        exit 1
    fi
    convert_lock_begin "$_GR_CONVERT_LOCK"

    # The view must be mounted and idle. The conversion copies the live view as it
    # is, so a mounted home is NOT refreshed: the refresh-only op_mount reported
    # "busy" one second after the supervisor closed the workspace and deferred the
    # whole run (#501, prod02 2026-10-14). Mount only when the home is not mounted.
    _GR_VIEW="$(readlink -f -- "$MNT_POINT" 2>/dev/null || printf '%s' "$MNT_POINT")"
    if ! mountpoint -q "$_GR_VIEW" 2>/dev/null; then
        if AICLI_CONVERT_SELF=1 op_mount "$TYPE" "$ID" "$PERSIST_PATH"; then :; else
            _gr_rc=$?
            [ "$_gr_rc" -eq 2 ] && _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "mount_busy" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\"}"
            error "Could not mount the home view for the conversion (rc=$_gr_rc)."
            exit 1
        fi
        _GR_VIEW="$(readlink -f -- "$MNT_POINT" 2>/dev/null || printf '%s' "$MNT_POINT")"
    fi
    # The workspaces were just closed: give their processes time to let go of the
    # home before deciding it is busy (AICLI_CONVERT_IDLE_WAIT_S, default 60).
    _gr_wait="${AICLI_CONVERT_IDLE_WAIT_S:-60}"
    case "$_gr_wait" in ''|*[!0-9]*) _gr_wait=60 ;; esac
    _gr_waited=0
    # An agent is busy while a process holds a file of its mounted versions
    # (a session of another agent that runs this agent's tool, for example).
    _gr_in_use() {
        if [ "$_GR_AGENT" != "1" ]; then home_mount_in_use "$MNT_POINT"; return; fi
        local _d
        command -v fuser >/dev/null 2>&1 || return 0
        for _d in "$(agent_versions_dir "$ID")"/*/ "$_GR_VIEW"; do
            _d="${_d%/}"
            [ -d "$_d" ] && mountpoint -q "$_d" 2>/dev/null || continue
            fuser -m "$_d" >/dev/null 2>&1 && return 0
        done
        return 1
    }
    while _gr_in_use && [ "$_gr_waited" -lt "$_gr_wait" ]; do
        sleep 2; _gr_waited=$((_gr_waited + 2))
    done
    if _gr_in_use; then
        log "Home is still busy after ${_gr_waited}s (a process holds it) — deferring the conversion."
        _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "mount_busy" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$MNT_POINT\"}"
    fi
    [ "$_gr_waited" -gt 0 ] && log "Home went idle after ${_gr_waited}s."
    mountpoint -q "$_GR_VIEW" 2>/dev/null || { error "Home view $_GR_VIEW is not mounted — refusing."; exit 1; }

    mapfile -t _GR_LAYERS < <(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID")
    [ "${#_GR_LAYERS[@]}" -gt 0 ] || _gr_precondition_refuse "no_layers_to_convert"
    _GR_LAYER_NAMES=""
    _GR_LAYERS_BYTES=0
    for _gr_l in "${_GR_LAYERS[@]}"; do
        _GR_LAYER_NAMES="${_GR_LAYER_NAMES:+$_GR_LAYER_NAMES,}\"$(basename "$_gr_l")\""
        _GR_LAYERS_BYTES=$(( _GR_LAYERS_BYTES + $(_gr_bytes "$(stat -c %s "$_gr_l" 2>/dev/null)") ))
    done
    _GR_PERSIST_DEV="$(stat -c %d "$PERSIST_PATH" 2>/dev/null)"
    _GR_UPPER_BYTES=0
    _GR_UPPER_SAME_FS=0
    if [ -d "$UPPER_DIR" ]; then
        if [ "$(stat -c %d "$UPPER_DIR" 2>/dev/null)" = "$_GR_PERSIST_DEV" ]; then
            _GR_UPPER_SAME_FS=1
            _GR_UPPER_BYTES="$(_gr_bytes "$(du -sB1 "$UPPER_DIR" 2>/dev/null | awk '{print $1}')")"
        fi
    fi

    _GR_PT_DIR="$PERSIST_PATH/passthrough/${TYPE}s/$ID"
    _GR_UPPER_KEEP="${UPPER_DIR}.convert-keep"
    _GR_STAGING_MARKER="$PERSIST_PATH/.graduate_staging_${TYPE}_${_GR_LOCK_ID}"
    _GR_VERIFIED_MARKER="$PERSIST_PATH/.graduate_verified_${TYPE}_${_GR_LOCK_ID}"
    _GR_AGENT_GEN=""
    if [ "$_GR_AGENT" = "1" ]; then
        # The version folder name is kept in the staging marker, so a retry after
        # a deferral copies into the same folder and converges.
        if [ -f "$_GR_STAGING_MARKER" ]; then
            _GR_AGENT_GEN="$(head -1 "$_GR_STAGING_MARKER" 2>/dev/null | tr -cd 'a-zA-Z0-9_-')"
        fi
        agent_convert_layout_paths "$PERSIST_PATH" "$ID"
        if [ -z "$_GR_AGENT_GEN" ]; then
            if _gr_why="$(agent_convert_occupied "$PERSIST_PATH" "$ID")"; then
                log "Plain folder of $ID is in the way: $_gr_why."
                _gr_precondition_refuse "pt_dir_occupied" "$AGC_STABLE" ""
            fi
            _GR_AGENT_GEN="$(agent_convert_gen_name)"
        fi
        _GR_PT_DIR="$AGC_VERSIONS/$_GR_AGENT_GEN"
    elif [ ! -f "$_GR_STAGING_MARKER" ] && [ ! -f "$(_intent_path "$PERSIST_PATH" "$TYPE" "$ID")" ] \
        && _gr_first="$(pt_dir_first_entry "$_GR_PT_DIR")"; then
        # #577: plugin housekeeping (".aicli/.*_done" zero-byte markers) is not data.
        _gr_precondition_refuse "pt_dir_occupied" "$_GR_PT_DIR" "$_gr_first"
    fi

    _GR_SQLITE_DBS="$(detect_sqlite_dbs "$_GR_VIEW" 2>/dev/null)"
    _GR_META="/tmp/unraid-aicliagents/.convert_${TYPE}_${_GR_LOCK_ID}_$$"
    mkdir -p "$_GR_META"
    _GR_EXCLUDES="$_GR_META/excludes"
    # An agent tree holds no cache folders to drop: copy every file.
    [ "$_GR_AGENT" = "1" ] && export CONVERT_NO_CACHE_EXCLUDES=1
    # shellcheck disable=SC2086
    convert_write_excludes "$_GR_EXCLUDES" "$_GR_VIEW" $_GR_SQLITE_DBS || { error "Cannot write the exclude list."; exit 1; }

    log "Measuring the home ($_GR_VIEW)..."
    # The whole view, caches included: a slight overestimate is the safe side.
    _GR_SRC_BYTES="$(_gr_bytes "$(du -sb "$_GR_VIEW" 2>/dev/null | awk '{print $1}')")"
    _GR_PERSIST_FREE="$(_gr_bytes "$(df -P -B1 "$PERSIST_PATH" 2>/dev/null | awk 'NR==2 {print $4}')")"
    if [ "$_GR_AGENT" = "1" ]; then
        # The plain copy is as big as the unpacked agent, scaled by the file
        # system's compression (ZFS compressratio; 100 = none). The old layers
        # stay beside it until the retention window ends, so the copy needs room now.
        _GR_RATIO="$(convert_fs_ratio "$PERSIST_PATH")"
        _GR_NEED="$(( _GR_SRC_BYTES * 100 / _GR_RATIO ))"
        _GR_MODE="$(convert_plan_mode "$_GR_PERSIST_FREE" "$_GR_NEED" 134217728)"
        if [ "$_GR_MODE" != "direct" ]; then
            _gr_reason "The boot disk has no room for the plain folder of this agent (about $(( _GR_NEED / 1048576 )) MB needed, $(( _GR_PERSIST_FREE / 1048576 )) MB free). Nothing was changed. Free some space (Home Storage, Boot disk space), then try again."
            error "Agent $ID needs about $_GR_NEED bytes for its plain folder, only $_GR_PERSIST_FREE are free — refused."
            rm -rf "$_GR_META" 2>/dev/null || true
            rm -f "$_GR_CONVERT_LOCK" 2>/dev/null || true
            _gr_precondition_refuse "boot_disk_too_small"
        fi
    else
        _GR_MODE="$(convert_plan_mode "$_GR_PERSIST_FREE" "$_GR_SRC_BYTES")"
    fi
    log "Home: $_GR_SRC_BYTES bytes to copy; boot disk free $_GR_PERSIST_FREE; layers $_GR_LAYERS_BYTES; writable layer $_GR_UPPER_BYTES (same disk: $_GR_UPPER_SAME_FS) — mode $_GR_MODE."

    _GR_WORK=""
    _GR_COPY="$_GR_PT_DIR"
    _GR_RETIRE="$PERSIST_PATH/.graduated/${TYPE}_${_GR_LOCK_ID}"
    if [ "$_GR_MODE" = "staged" ]; then
        _GR_WORK_NEED=$(( _GR_SRC_BYTES + _GR_LAYERS_BYTES + _GR_UPPER_BYTES + 1073741824 ))
        if ! _GR_WORK="$(work_folder_choose "$_GR_WORK_NEED" "$PERSIST_PATH")"; then
            error "The boot disk has no room for a copy of the home (needs $_GR_SRC_BYTES bytes, $_GR_PERSIST_FREE free), and no work folder on another disk has $_GR_WORK_NEED bytes free. Start the array, or set the work folder for storage jobs."
            _gr_precondition_refuse "no_work_folder"
        fi
        _GR_COPY="$_GR_WORK/copy/${TYPE}_${_GR_LOCK_ID}"
        _GR_RETIRE="$_GR_WORK/retired/${TYPE}_${_GR_LOCK_ID}"
        log "Staged mode: work folder $_GR_WORK."
    fi
    # R-095: a second conversion inside the retention window finds the folder of
    # the first one. mv would then move the writable layer INTO its "upper"
    # (upper/upper) and mix the layers of two conversions. Take a new folder name;
    # the intent records the path, so a roll back and the supervisor use this one.
    if [ -e "$_GR_RETIRE" ]; then
        _GR_RETIRE="${_GR_RETIRE}.$(date -u +%Y%m%dT%H%M%SZ)"
        log "The retire folder of an earlier conversion still exists; using $_GR_RETIRE."
    fi

    # R14: the tray reads the copy progress and the measured total from these.
    _GR_PROG="/tmp/unraid-aicliagents/.convert_progress_${TYPE}_${_GR_LOCK_ID}.log"
    printf '%s\n' "$_GR_SRC_BYTES" > "/tmp/unraid-aicliagents/.convert_total_${TYPE}_${_GR_LOCK_ID}" 2>/dev/null || true
    : > "$_GR_PROG" 2>/dev/null || true
    _gr_cleanup_meta() {
        rm -rf "$_GR_META" "$_GR_PROG" "/tmp/unraid-aicliagents/.convert_total_${TYPE}_${_GR_LOCK_ID}" "$_GR_CONVERT_LOCK" 2>/dev/null || true
    }

    # ---- copy + verify (nothing destructive yet) ----
    _gr_phase "copying"
    if [ "$_GR_AGENT" = "1" ]; then
        printf '%s\n' "$_GR_AGENT_GEN" > "$_GR_STAGING_MARKER" 2>/dev/null || true
        printf 'copy=%s\n' "$_GR_COPY" >> "$_GR_STAGING_MARKER" 2>/dev/null || true
        mkdir -p "$AGC_VERSIONS" 2>/dev/null || true
    elif [ "$FORCE_DIRECT" = "1" ]; then
        printf '%s\n' "$_GR_COPY" > "$_GR_STAGING_MARKER" 2>/dev/null || true
    else
        : > "$_GR_STAGING_MARKER" 2>/dev/null || true
    fi
    # A user home conversion keeps its copy so a retry can converge over it.
    # A global migration has no retry after a hard copy failure, so remove the
    # partial copy and keep the marker only if removal failed.
    _gr_fail_cleanup() {
        [ "$_GR_AGENT" = "1" ] || [ "$FORCE_DIRECT" = "1" ] || return 0
        local _gr_failed_copy="${_GR_COPY:-$_GR_PT_DIR}"
        if ! convert_cleanup_failed_copy "$_gr_failed_copy" "$_GR_STAGING_MARKER"; then
            _gr_reason "An unfinished conversion copy remains at $_gr_failed_copy. Remove it from Boot disk space under Left over."
            error "Unfinished conversion copy remains at $_gr_failed_copy; its staging marker stays in place."
        fi
        if [ "$_GR_AGENT" = "1" ]; then rmdir "$AGC_VERSIONS" 2>/dev/null || true; fi
    }
    log "Copying to $_GR_COPY..."
    if ! convert_copy_tree "$_GR_VIEW" "$_GR_COPY" "$_GR_EXCLUDES" "$_GR_PROG" 2>>"$DEBUG_LOG"; then
        _gr_reason "Copying failed (disk full or a read error). Nothing was changed."
        error "Copy failed — layers untouched."
        _gr_fail_cleanup; _gr_cleanup_meta; exit 1
    fi
    if [ -n "$_GR_SQLITE_DBS" ]; then
        _gr_sba=0
        # shellcheck disable=SC2086
        AICLI_SQLITE_READ_ROOT="" sqlite_backup_all "$_GR_VIEW" "$_GR_COPY" $_GR_SQLITE_DBS || _gr_sba=$?
        if [ "$_gr_sba" -ne 0 ]; then
            _gr_reason "A database could not be copied (${SQLITE_BACKUP_FAILED_DB:-a database}). Nothing was changed."
            error "SQLite copy of ${SQLITE_BACKUP_FAILED_DB:-a database} failed: ${SQLITE_BACKUP_ERROR:-no message} — layers untouched."
            _gr_fail_cleanup; _gr_cleanup_meta; exit 1
        fi
    fi
    # #502: the plugin's own services (Relay, hub, resume records) keep writing
    # small files into the home; copy the changes again until the copy matches.
    if ! _gr_why="$(convert_sync_until_clean "$_GR_VIEW" "$_GR_COPY" "$_GR_EXCLUDES" 5 2>>"$DEBUG_LOG")"; then
        _gr_reason "Files in the home kept changing during the copy. Nothing was changed; try again."
        error "Copy verify failed: files kept changing during the copy ($_gr_why) — layers untouched."
        _gr_fail_cleanup; _gr_cleanup_meta; exit 1
    fi
    # shellcheck disable=SC2086
    if ! _gr_why="$(convert_verify_dbs "$_GR_VIEW" "$_GR_COPY" $_GR_SQLITE_DBS)"; then
        _gr_reason "A database copy is missing. Nothing was changed."
        error "Copy verify failed: $_gr_why — layers untouched."
        _gr_fail_cleanup; _gr_cleanup_meta; exit 1
    fi
    log "Copy verified."

    if [ "$_GR_MODE" = "staged" ]; then
        _GR_EST="$(_gr_bytes "$(convert_copy_size_on "$_GR_COPY" "$PERSIST_PATH")")"
        _GR_PERSIST_FREE="$(_gr_bytes "$(df -P -B1 "$PERSIST_PATH" 2>/dev/null | awk 'NR==2 {print $4}')")"
        _gr_upper_freed=0; [ "$_GR_UPPER_SAME_FS" = "1" ] && _gr_upper_freed="$_GR_UPPER_BYTES"
        if ! convert_fits_after_free "$_GR_PERSIST_FREE" "$_GR_LAYERS_BYTES" "$_gr_upper_freed" "$_GR_EST"; then
            error "The home needs about $_GR_EST bytes on the boot disk, but only $(( _GR_PERSIST_FREE + _GR_LAYERS_BYTES + _gr_upper_freed )) bytes are free even after the old layers move off it. Nothing was changed."
            _gr_fail_cleanup
            _gr_cleanup_meta
            _gr_precondition_refuse "boot_disk_too_small"
        fi
        log "The plain home will take about $_GR_EST bytes on the boot disk; it fits after the layers move off."
    fi

    # ---- critical section: bake lock, unmount, intent, retire ----
    if ! convert_acquire_graduate_bake_lock "$TYPE" "$_GR_LOCK_ID" "$FORCE_DIRECT"; then
        _gr_cleanup_meta
        _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "bake_lock_held"
    fi
    mapfile -t _GR_NOW < <(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID")
    if [ "${_GR_NOW[*]}" != "${_GR_LAYERS[*]}" ]; then
        _gr_cleanup_meta
        _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "bake_landed_during_consolidate" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\"}"
    fi
    # #502: the last pass, as close to the unmount as possible. What the plugin's
    # services write between this pass and the unmount (seconds: Relay status,
    # nonces) is not carried over; they rebuild it on the next contact.
    _gr_phase "copying"
    if ! _gr_why="$(convert_sync_until_clean "$_GR_VIEW" "$_GR_COPY" "$_GR_EXCLUDES" 3 2>>"$DEBUG_LOG")"; then
        _gr_cleanup_meta
        _gr_reason "Files in the home kept changing just before the switch. Nothing was changed; try again."
        error "Copy verify failed just before the switch: files kept changing ($_gr_why) — layers untouched."
        _gr_fail_cleanup
        exit 1
    fi
    log "Final pass done: the copy matches the home."
    # An agent has one mount for each version it served (agents/.versions/<id>/<gen>):
    # release them all. A home has one.
    _gr_teardown() {
        if [ "$_GR_AGENT" != "1" ]; then _mount_teardown_arbiter "$MNT_POINT"; return; fi
        local _d _rc=0
        for _d in "$(agent_versions_dir "$ID")"/*/; do
            _d="${_d%/}"
            [ -d "$_d" ] && mountpoint -q "$_d" 2>/dev/null || continue
            _mount_teardown_arbiter "$_d" || { _rc=$?; return "$_rc"; }
        done
        _mount_teardown_arbiter "$_GR_VIEW"
    }
    if _gr_teardown; then :; else
        _gr_cleanup_meta
        log "The view is still busy — deferring (nothing changed)."
        _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "mount_busy" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\"}"
    fi
    for _gr_lm in /tmp/unraid-aicliagents/mnt/${TYPE}_${ID}_*; do
        [ -d "$_gr_lm" ] || continue
        layer_name_is_of "$TYPE" "$ID" "${_gr_lm##*/}" || continue   # R-002 (#580): not another entity's mount
        # #504: a real unmount only. A lazy one hides the mount but keeps the
        # squashfs and its loop alive while anything still uses it, so a moved
        # layer keeps its space on the boot disk. release_layer_loops below
        # then stops the run before anything moves.
        mountpoint -q "$_gr_lm" 2>/dev/null && { umount "$_gr_lm" 2>/dev/null || true; }
        rmdir "$_gr_lm" 2>/dev/null || true
    done
    # #504: staged mode frees the boot disk by moving the layers to the work disk.
    # A layer that a loop device still reads keeps its space after the move
    # (until a reboot), and the copy back then runs out of room. Release every
    # loop first; when one stays, stop here, before anything moved.
    if [ "$_GR_MODE" = "staged" ] && ! _gr_why="$(release_layer_loops "${_GR_LAYERS[@]}")"; then
        _gr_cleanup_meta
        _gr_reason "The system still holds the old layers open, so moving them would not free the boot disk. Nothing was changed. Restart the server, then try again."
        error "Layer loop devices stay attached after the unmount: $(printf '%s' "$_gr_why" | tr '\n' ';') — conversion stopped before any move."
        _gr_fail_cleanup
        lifecycle_log "error" "graduate" "convert_loops_pinned" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\"}" 2>/dev/null || true
        exit 1
    fi

    # The stable link goes in BEFORE the intent: a crash after the manifest flip
    # then finds a complete plain layout. While layers exist the entity stays
    # layered, so the link is inert until the flip.
    if [ "$_GR_AGENT" = "1" ] && ! agent_convert_link_layout "$PERSIST_PATH" "$ID" "$_GR_AGENT_GEN"; then
        _gr_reason "The stable link of the plain folder could not be made. Nothing was changed."
        error "Cannot link $AGC_STABLE to the version folder $_GR_AGENT_GEN."
        _gr_fail_cleanup; _gr_cleanup_meta; exit 1
    fi
    if ! write_intent "$PERSIST_PATH" "$TYPE" "$ID" \
        "{\"op\":\"graduate\",\"mode\":\"$_GR_MODE\",\"keep\":\"\",\"pt_dir\":\"$_GR_PT_DIR\",\"copy\":\"$_GR_COPY\",\"retire\":\"$_GR_RETIRE\",\"upper\":\"$UPPER_DIR\",\"delete\":[$_GR_LAYER_NAMES]}"; then
        # R-020 (#598): no plan on disk, so no layer may move.
        _gr_reason "The plan for the change could not be saved (disk full?). Nothing was changed."
        error "Cannot write the conversion intent — conversion stopped before any move."
        _gr_fail_cleanup; _gr_cleanup_meta; exit 1
    fi
    _itest_sigkill_window "graduate_after_intent"

    # _gr_rollback — R5: put the layers and the writable layer back, drop the
    # partial plain folder, clear the intent. The home is as it was.
    # #505: convert_rollback checks the room first. With too little room it moves
    # NOTHING and the intent stays open: the home stays closed (op_mount refuses)
    # and the supervisor retries the rollback each tick, instead of a half-moved
    # home that a workspace then starts on.
    _gr_rollback() {
        local _rb_rc=0
        error "Rolling back: putting the layers and the writable layer back."
        rm -f "$_GR_VERIFIED_MARKER" 2>/dev/null || true
        convert_rollback "$PERSIST_PATH" "$_GR_PT_DIR" "$_GR_RETIRE" "$UPPER_DIR" 2>>"$DEBUG_LOG" || _rb_rc=$?
        if [ "$_rb_rc" -ne 0 ]; then
            if [ "$_rb_rc" -eq 3 ]; then
                _gr_reason "The boot disk has too little room to put the old layers back. They are safe in $_GR_RETIRE. The home stays closed; free some space on the boot disk and the plugin puts them back."
            else
                _gr_reason "Putting the old layers back stopped part way. They are safe in $_GR_RETIRE. The home stays closed; the plugin tries again."
            fi
            error "ROLLBACK incomplete (rc=$_rb_rc): the intent stays open; the supervisor retries. Files: $_GR_RETIRE"
            lifecycle_log "error" "graduate" "convert_rollback_incomplete" \
                "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"rc\":$_rb_rc,\"retire\":\"$_GR_RETIRE\"}" 2>/dev/null || true
            return 0
        fi
        [ "$_GR_AGENT" = "1" ] && agent_convert_unlink_layout "$PERSIST_PATH" "$ID" "$_GR_AGENT_GEN"
        clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
        if [ "$_GR_MODE" = "staged" ] && [ "$_GR_COPY" != "$_GR_PT_DIR" ]; then
            if ! convert_cleanup_failed_copy "$_GR_COPY" "$_GR_STAGING_MARKER"; then
                _gr_reason "An unfinished conversion copy remains at $_GR_COPY. Remove it from Boot disk space under Left over."
                error "Unfinished conversion copy remains at $_GR_COPY; its staging marker stays in place."
            fi
        else
            rm -f "$_GR_STAGING_MARKER" 2>/dev/null || true
        fi
        lifecycle_log "warn" "graduate" "convert_rolled_back" \
            "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mode\":\"$_GR_MODE\"}" 2>/dev/null || true
    }

    _gr_phase "freeing"
    mkdir -p "$_GR_RETIRE" 2>/dev/null
    log "Moving the old layers to $_GR_RETIRE (kept ${graduated_retention_days:-14} days)..."
    for _gr_l in "${_GR_LAYERS[@]}"; do
        if ! mv -f "$_gr_l" "$_GR_RETIRE/" 2>>"$DEBUG_LOG"; then
            error "Could not move $(basename "$_gr_l")."
            _gr_rollback; _gr_cleanup_meta; exit 1
        fi
    done
    if [ -d "$UPPER_DIR" ]; then
        if [ "$_GR_UPPER_SAME_FS" = "1" ] || [ "$_GR_MODE" = "staged" ]; then
            if ! mv -f "$UPPER_DIR" "$_GR_RETIRE/upper" 2>>"$DEBUG_LOG"; then
                error "Could not move the writable layer."
                _gr_rollback; _gr_cleanup_meta; exit 1
            fi
        else
            # A writable layer on another file system (RAM): its content is in
            # the copy, but the copy is the only other holder until the switch is
            # done. R-001 (#579): rename it aside on its own file system and
            # delete it only after clear_intent. convert_rollback puts it back.
            rm -rf "$_GR_UPPER_KEEP" 2>/dev/null || true
            if ! mv -f "$UPPER_DIR" "$_GR_UPPER_KEEP" 2>>"$DEBUG_LOG"; then
                error "Could not set the writable layer aside."
                _gr_rollback; _gr_cleanup_meta; exit 1
            fi
        fi
        mkdir -p "$UPPER_DIR" 2>/dev/null || true
    fi
    _itest_sigkill_window "graduate_after_move"

    if [ "$_GR_MODE" = "staged" ]; then
        _gr_phase "copying-back"
        log "Copying the home back to $_GR_PT_DIR..."
        if ! convert_copy_tree "$_GR_COPY" "$_GR_PT_DIR" "" "$_GR_PROG" 2>>"$DEBUG_LOG"; then
            _gr_reason "Copying the home back to the boot disk failed. The home was put back as it was."
            error "Copy back to the boot disk failed."
            _gr_rollback; _gr_cleanup_meta; exit 1
        fi
        if ! _gr_why="$(convert_verify_tree "$_GR_COPY" "$_GR_PT_DIR")"; then
            _gr_reason "The copy on the boot disk did not match. The home was put back as it was."
            error "Verify of the copy on the boot disk failed: $_gr_why"
            _gr_rollback; _gr_cleanup_meta; exit 1
        fi
    fi
    : > "$_GR_VERIFIED_MARKER" 2>/dev/null || true
    sync "$PERSIST_PATH" 2>/dev/null || sync

    _gr_phase "switching"
    if ! manifest_set_backend "$TYPE" "$ID" "passthrough"; then
        _gr_reason "The switch to plain folders failed. The home was put back as it was."
        error "Manifest switch failed."
        _gr_rollback; _gr_cleanup_meta; exit 1
    fi
    _itest_sigkill_window "graduate_after_manifest"
    clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
    rm -rf "$_GR_UPPER_KEEP" 2>/dev/null || true
    rm -f "$_GR_STAGING_MARKER" "$_GR_VERIFIED_MARKER" 2>/dev/null || true
    if [ "$_GR_MODE" = "staged" ]; then
        rm -rf "$_GR_COPY" 2>/dev/null
        # R4: the supervisor reaps this retire folder after the retention window.
        touch "$_GR_RETIRE" 2>/dev/null || true
        printf '%s\n' "$_GR_RETIRE" >> "${AICLI_RETIRED_LIST:-/boot/config/plugins/unraid-aicliagents/retired_dirs.list}" 2>/dev/null || true
    fi
    _gr_cleanup_meta

    lifecycle_log "info" "graduate" "graduate_ok" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mode\":\"$_GR_MODE\",\"pt_dir\":\"$_GR_PT_DIR\",\"retired\":\"$_GR_RETIRE\",\"bytes\":$_GR_SRC_BYTES}" 2>/dev/null || true
    log "Conversion complete: $TYPE $ID is plain folders at $_GR_PT_DIR (old layers kept in $_GR_RETIRE)."

    if [ "$_GR_AGENT" = "1" ]; then
        # Bind the new version at its own path and point the stable mount name at
        # it, as _pt_mount_versioned does. Not fatal: the next mount does the same.
        # The layered versions are gone: drop their state records (RAM) so the card
        # does not count generations that no longer exist.
        rm -f "$(agent_generation_state_dir "$ID")"/*.env 2>/dev/null || true
        _gr_target="$(agent_versioned_mount "$ID" "$_GR_AGENT_GEN")"
        mkdir -p "$_gr_target" 2>/dev/null || true
        if mount --bind "$_GR_PT_DIR" "$_gr_target" 2>/dev/null \
            && agent_activate_stable_symlink "$ID" "$_GR_AGENT_GEN" "$_gr_target"; then
            lifecycle_log "info" "storagectl" "passthrough_generation_activated" \
                "{\"type\":\"agent\",\"id\":\"$ID\",\"generation\":\"$_GR_AGENT_GEN\"}" 2>/dev/null || true
            log "Plain version $_GR_AGENT_GEN of $ID is bound at $_gr_target."
        else
            log "Binding the plain version failed (non-fatal) — the next mount binds it."
        fi
        exit 0
    fi

    [ -L "$MNT_POINT" ] && rm -f "$MNT_POINT"
    mkdir -p "$MNT_POINT" 2>/dev/null
    if [ "$ID" != "root" ] && id "$ID" >/dev/null 2>&1; then
        chown "$ID" "$_GR_PT_DIR" "$MNT_POINT" 2>/dev/null || true
    fi
    if mount --bind "$_GR_PT_DIR" "$MNT_POINT" 2>/dev/null; then
        lifecycle_log "info" "storagectl" "passthrough_mount" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"dir\":\"$_GR_PT_DIR\"}" 2>/dev/null || true
        log "Plain home bind mounted at $MNT_POINT."
        # BOOT_DISK_SPACE.md R5: the caches of the new plain home go to RAM or a pool.
        { declare -f home_cache_mount >/dev/null 2>&1 && home_cache_mount "$ID" "$ID" "$MNT_POINT" "$PERSIST_PATH" 2>/dev/null; } || true
    else
        log "Bind mount failed (non-fatal) — the next mount binds the plain folder."
    fi
    exit 0
fi

# ---- 2. Refuse an occupied legacy target before saving any layers ------------
# A refusal must not bake or consolidate the source before it reports the path
# that blocks conversion. Staged retries and open intents keep their target.
_GR_PT_DIR="$PERSIST_PATH/passthrough/${TYPE}s/$ID"
_GR_STAGING_MARKER="$PERSIST_PATH/.graduate_staging_${TYPE}_${_GR_LOCK_ID}"
_GR_INTENT_PATH="$(_intent_path "$PERSIST_PATH" "$TYPE" "$ID")"
if _gr_first="$(graduate_legacy_target_preflight "$_GR_PT_DIR" "$_GR_STAGING_MARKER" "$_GR_INTENT_PATH")"; then :; else
    _gr_precondition_refuse "pt_dir_occupied" "$_GR_PT_DIR" "$_gr_first"
fi

# ---- 2. Flush the upper (op_bake path; serialises itself on the bake lock) ---
log "Flushing upper before migration (op_bake)..."
if op_bake "$TYPE" "$ID" "$PERSIST_PATH"; then :; else
    _gr_rc=$?
    if [ "$_gr_rc" -eq 2 ]; then
        log "Flush deferred (busy) — graduate deferred; retry when idle."
        exit 2    # op_bake already wrote its reason marker + lifecycle event
    fi
    [ "$_gr_rc" -eq 4 ] && exit 4
    error "Flush bake failed (rc=$_gr_rc) — aborting graduate."
    exit 1
fi

# ---- 3. Consolidate to ONE layer (only when >1; a single layer of any kind
#         already holds the entity's complete durable content) ----------------
_GR_COUNT="$(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID" | awk 'NF{c++}END{print c+0}')"
if [ "$_GR_COUNT" -gt 1 ]; then
    log "Consolidating $_GR_COUNT layers to one (op_consolidate)..."
    if op_consolidate "$TYPE" "$ID" "$PERSIST_PATH"; then :; else
        _gr_rc=$?
        if [ "$_gr_rc" -eq 2 ]; then
            log "Consolidate deferred — graduate deferred; retry when idle."
            exit 2
        fi
        [ "$_gr_rc" -eq 4 ] && exit 4
        error "Consolidate failed (rc=$_gr_rc) — aborting graduate (nothing destructive happened)."
        exit 1
    fi
fi

# Re-discover: the migration source must be EXACTLY one layer.
mapfile -t _GR_LAYERS < <(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID")
if [ "${#_GR_LAYERS[@]}" -eq 0 ]; then
    error "No layers on disk after flush/consolidate — nothing to graduate (inconsistent state)."
    exit 1
fi
if [ "${#_GR_LAYERS[@]}" -gt 1 ]; then
    log "More than one layer after consolidate (a bake landed) — deferring."
    _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "bake_landed_during_consolidate" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"layers\":${#_GR_LAYERS[@]}}"
fi
_GR_SRC_LAYER="${_GR_LAYERS[0]}"
_GR_SRC_BASE="$(basename "$_GR_SRC_LAYER")"

# ---- 4. Idle + flushed checks ------------------------------------------------
if home_mount_in_use "$MNT_POINT"; then
    log "Home is busy (live session) — deferring graduate."
    _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "mount_busy" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"mount_point\":\"$MNT_POINT\"}"
fi
# The upper must be EMPTY of files: anything left was NOT captured by the
# flush/consolidate above (e.g. writes that landed mid-bake) and would be
# silently stranded once the entity stops using the layering engine.
if [ -d "$UPPER_DIR" ] && [ -n "$(find "$UPPER_DIR" -type f -print -quit 2>/dev/null)" ]; then
    log "Upper still holds unflushed files after flush+consolidate — deferring graduate."
    _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "upper_not_empty" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"upper\":\"$UPPER_DIR\"}"
fi

# ---- 5. Copy the consolidated content into the passthrough plain dir ---------
# Layout MUST match storagectl _pt_dir: $PERSIST/passthrough/<type>s/<id>
_GR_SCRATCH="/tmp/unraid-aicliagents/.graduate_src_${TYPE}_${_GR_LOCK_ID}_$$"
mkdir -p "$_GR_SCRATCH" 2>/dev/null
_gr_cleanup_scratch() {
    umount_or_keep "$_GR_SCRATCH" "conversion scratch" 3 || return 0   # #512: never lazy; a kept mount keeps its dir
    rmdir "$_GR_SCRATCH" 2>/dev/null || true
}
if ! mount -o loop,ro "$_GR_SRC_LAYER" "$_GR_SCRATCH" 2>/dev/null; then
    error "Cannot mount $_GR_SRC_BASE read-only for the copy."
    _gr_cleanup_scratch
    exit 1
fi

# Disk-space preflight: the plain copy needs roughly the UNCOMPRESSED size.
_GR_SRC_BYTES="$(du -sb "$_GR_SCRATCH" 2>/dev/null | awk '{print $1}')"
case "$_GR_SRC_BYTES" in ''|*[!0-9]*) _GR_SRC_BYTES=0 ;; esac
_GR_NEED_MB=$(( _GR_SRC_BYTES / 1048576 + 100 ))
if ! check_disk_space "$PERSIST_PATH/.diskcheck" "$_GR_NEED_MB"; then
    error "Insufficient space on $PERSIST_PATH for the passthrough copy (${_GR_NEED_MB}MB needed)."
    _gr_cleanup_scratch
    exit 1
fi

: > "$_GR_STAGING_MARKER" 2>/dev/null || true
if [ "$FORCE_DIRECT" = "1" ]; then
    printf '%s\n' "$_GR_PT_DIR" > "$_GR_STAGING_MARKER" 2>/dev/null || true
fi
mkdir -p "$_GR_PT_DIR" 2>/dev/null
log "Copying $_GR_SRC_BASE → $_GR_PT_DIR (rsync -aHX, $_GR_SRC_BYTES bytes)..."
if ! convert_rsync_plain_tree "$_GR_SCRATCH" "$_GR_PT_DIR" "$_GR_STAGING_MARKER" "$FORCE_DIRECT" 2>>"$DEBUG_LOG"; then
    error "rsync into the passthrough dir failed — aborting (layers untouched)."
    _gr_cleanup_scratch
    exit 1
fi

# ---- 6. Verify the full tree, then read back sample file hashes ---------------
if ! _GR_VERIFY_FAIL="$(convert_verify_tree "$_GR_SCRATCH" "$_GR_PT_DIR" "" 20)"; then
    error "Copy verify failed: $_GR_VERIFY_FAIL — aborting (layers untouched)."
    if [ "$FORCE_DIRECT" = "1" ]; then convert_cleanup_failed_copy "$_GR_PT_DIR" "$_GR_STAGING_MARKER" || true; fi
    _gr_cleanup_scratch
    exit 1
fi
_gr_cleanup_scratch
log "Copy verified (full tree matches; sampled sha256 checks passed)."

# ---- 7. CRITICAL SECTION: per-entity bake lock -------------------------------
# Same lock op_bake holds (fd 9) — excludes a concurrent bake AND the
# supervisor's reconcile pass for the whole authority flip below.
if ! convert_acquire_graduate_bake_lock "$TYPE" "$_GR_LOCK_ID" "$FORCE_DIRECT"; then
    log "Per-entity bake lock held — deferring graduate."
    _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "bake_lock_held"
fi

# Re-validate under the lock: the layer set must still be exactly the one we
# copied. A delta that landed during the rsync makes the pt copy stale — defer
# (the copy converges on retry; nothing destructive has happened).
mapfile -t _GR_NOW_LAYERS < <(_layer_discover_sorted "$PERSIST_PATH" "$TYPE" "$ID")
if [ "${#_GR_NOW_LAYERS[@]}" -ne 1 ] || [ "$(basename "${_GR_NOW_LAYERS[0]}")" != "$_GR_SRC_BASE" ]; then
    log "Layer set changed during the copy (a bake landed) — deferring graduate."
    _op_defer "$TYPE" "$ID" "graduate" "graduate_deferred" "bake_landed_during_consolidate" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"expected\":\"$_GR_SRC_BASE\"}"
fi

# ---- 8. Write-ahead intent → move → manifest flip → clear --------------------
# Intent format mirrors consolidate's ({"op",...,"delete":[...]}) so the
# reconcile/boot intentional-prune readers treat the moved layer as benign.
if ! write_intent "$PERSIST_PATH" "$TYPE" "$ID" \
    "{\"op\":\"graduate\",\"keep\":\"\",\"pt_dir\":\"$_GR_PT_DIR\",\"files\":$_GR_DST_COUNT,\"delete\":[\"$_GR_SRC_BASE\"]}"; then
    # R-020 (#598): no plan on disk, so the layer must not move.
    error "Cannot write the graduate intent (disk full?) — nothing moved, layers authoritative."
    if [ "$FORCE_DIRECT" = "1" ]; then convert_cleanup_failed_copy "$_GR_PT_DIR" "$_GR_STAGING_MARKER" || true; fi
    exit 1
fi
_itest_sigkill_window "graduate_after_intent"

_GR_RETIRE_DIR="$PERSIST_PATH/.graduated/${TYPE}_${_GR_LOCK_ID}"
mkdir -p "$_GR_RETIRE_DIR" 2>/dev/null
log "Retiring layer to $_GR_RETIRE_DIR (move, NOT delete — 14-day retention)..."
if ! mv -f "$_GR_SRC_LAYER" "$_GR_RETIRE_DIR/" 2>>"$DEBUG_LOG"; then
    error "Failed to move $_GR_SRC_BASE to .graduated/ — rolling back (clearing intent; layers authoritative)."
    clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
    if [ "$FORCE_DIRECT" = "1" ]; then convert_cleanup_failed_copy "$_GR_PT_DIR" "$_GR_STAGING_MARKER" || true; fi
    exit 1
fi
_itest_sigkill_window "graduate_after_move"

# The authority flip: backend=passthrough + expected_layers cleared in ONE
# locked manifest write (LayerManifestService::setBackend).
if ! manifest_set_backend "$TYPE" "$ID" "passthrough"; then
    error "Manifest backend flip FAILED — rolling back (restoring layer from .graduated/)."
    mv -f "$_GR_RETIRE_DIR/$_GR_SRC_BASE" "$PERSIST_PATH/" 2>>"$DEBUG_LOG" || \
        error "ROLLBACK MOVE FAILED — layer remains at $_GR_RETIRE_DIR/$_GR_SRC_BASE (recover manually)."
    clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
    if [ "$FORCE_DIRECT" = "1" ]; then convert_cleanup_failed_copy "$_GR_PT_DIR" "$_GR_STAGING_MARKER" || true; fi
    exit 1
fi
_itest_sigkill_window "graduate_after_manifest"

rm -f "$_GR_STAGING_MARKER" 2>/dev/null || true
clear_intent "$PERSIST_PATH" "$TYPE" "$ID"
lifecycle_log "info" "graduate" "graduate_ok" \
    "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"pt_dir\":\"$_GR_PT_DIR\",\"retired\":\"$_GR_SRC_BASE\",\"files\":$_GR_DST_COUNT,\"bytes\":$_GR_SRC_BYTES}" 2>/dev/null || true
log "Graduation complete: $TYPE/$ID is now passthrough at $_GR_PT_DIR ($_GR_SRC_BASE retained in .graduated/)."

# ---- 9. Remount via the passthrough path (best-effort) ------------------------
# Tear down the flash overlay (its lowers point at the retired layer's loop
# mounts) and bind the plain dir at the entity's normal mount point — the same
# bind _pt_mount performs. A busy/phantom mount is left alone: the graduation
# is durable, and the next normal mount routes through the passthrough guard.
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): tear down whatever the
# stable name REALLY resolves to (agent_mount_real) -- $MNT_POINT may still be
# the stable symlink here, and /proc/mounts (which the arbiter's busy re-check
# reads) never shows that name once an agent is migrated. No-op for home / a
# not-yet-migrated agent, same as every other arbiter call site touched here.
_GR_TEARDOWN_TARGET="$MNT_POINT"
if [ "$TYPE" = "agent" ] && declare -f agent_mount_real >/dev/null 2>&1; then
    _GR_TEARDOWN_TARGET="$(agent_mount_real "$ID")"
fi
if _mount_teardown_arbiter "$_GR_TEARDOWN_TARGET"; then _GR_TD=0; else _GR_TD=$?; fi
if [ "$_GR_TD" -eq 0 ]; then
    # Lift this entity's now-orphaned layer loop mounts.
    for _gr_lm in /tmp/unraid-aicliagents/mnt/${TYPE}_${ID}_*; do
        [ -d "$_gr_lm" ] || continue
        layer_name_is_of "$TYPE" "$ID" "${_gr_lm##*/}" || continue   # R-002 (#580): not another entity's mount
        umount_or_keep "$_gr_lm" "layer mount" 3 || continue   # #512: never lazy; a kept mount keeps its dir
        rmdir "$_gr_lm" 2>/dev/null || true
    done
    [ -L "$MNT_POINT" ] && rm -f "$MNT_POINT"
    mkdir -p "$MNT_POINT" 2>/dev/null
    _GR_OWNER=""
    [ "$TYPE" = "home" ] && _GR_OWNER="$ID"
    if [ -n "$_GR_OWNER" ] && [ "$_GR_OWNER" != "root" ] && id "$_GR_OWNER" >/dev/null 2>&1; then
        chown "$_GR_OWNER" "$_GR_PT_DIR" "$MNT_POINT" 2>/dev/null || true
    fi
    if mount --bind "$_GR_PT_DIR" "$MNT_POINT" 2>/dev/null; then
        lifecycle_log "info" "storagectl" "passthrough_mount" "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"dir\":\"$_GR_PT_DIR\"}" 2>/dev/null || true
        log "Passthrough bind mounted at $MNT_POINT."
        # BOOT_DISK_SPACE.md R5: a plain home gets its cache binds (agents do not).
        if [ "$TYPE" = "home" ] && declare -f home_cache_mount >/dev/null 2>&1; then
            home_cache_mount "$ID" "$ID" "$MNT_POINT" "$PERSIST_PATH" 2>/dev/null
        fi
    else
        log "Passthrough bind failed (non-fatal) — the next mount binds via the passthrough path."
    fi
else
    log "Mount point busy/phantom (rc=$_GR_TD) — leaving as-is; the next mount cycle binds the passthrough dir."
    lifecycle_log "warn" "graduate" "graduate_remount_deferred" \
        "{\"type\":\"$TYPE\",\"id\":\"$ID\",\"rc\":$_GR_TD}" 2>/dev/null || true
fi
exit 0
)
