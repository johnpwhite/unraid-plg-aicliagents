#!/bin/bash
# AICliAgents: Shared Storage Functions
# Sourced by all storage scripts for consistent validation and logging.

PLUGIN_BIN="/usr/local/emhttp/plugins/unraid-aicliagents/bin"
ZRAM_BASE="/tmp/unraid-aicliagents/zram_upper"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"

# #1322: detect_backend.sh provides the GENUINE device test (entity_upper_mode /
# backend_for) that _entity_paths uses for the zram-vs-disk upper-mode. It is a
# self-contained, dependency-free pure-function lib — source it here (guarded) so it
# is available everywhere common.sh is, not only where storagectl sources it.
if ! declare -f entity_upper_mode >/dev/null 2>&1; then
    _cmn_storage_dir="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
    if [ -f "$_cmn_storage_dir/detect_backend.sh" ]; then
        # shellcheck source=detect_backend.sh
        source "$_cmn_storage_dir/detect_backend.sh" 2>/dev/null || true
    fi
    unset _cmn_storage_dir
fi

# Ensure debug log directory exists
mkdir -p "$(dirname "$DEBUG_LOG")"

export PATH="$PLUGIN_BIN:$PATH"

get_ts() { date '+%Y-%m-%d %H:%M:%S'; }

# ---------------------------------------------------------------------------
# R-06 (#1370) trace correlation.
# _trace_tag -> "[t:<id>] " when AICLI_TRACE_ID is inherited (exported by the
# PHP exec sites / the supervisor's op dispatch), '' otherwise. Storage scripts
# splice it into their log prefixes so a debug.log grep on [t:<id>] joins the
# shell lines to the originating AJAX request.
# ---------------------------------------------------------------------------
_trace_tag() { printf '%s' "${AICLI_TRACE_ID:+[t:$AICLI_TRACE_ID] }"; }

# cmn_log <LEVL> <CTX> <message...> — THE central debug-log writer for storage
# scripts: "[ts] [LEVL] [CTX] [t:<id>] message". New code should use this (or a
# per-script wrapper that includes $(_trace_tag)) instead of hand-rolled echoes.
cmn_log() {
    local _cl_level="${1:-INFO}" _cl_ctx="${2:-storage}"
    shift 2 2>/dev/null || true
    echo "[$(get_ts)] [$_cl_level] [$_cl_ctx] $(_trace_tag)$*" >> "$DEBUG_LOG"
}

# guard_path: Validate a path before destructive operations.
# Usage: guard_path "/some/path" "description"
# Returns 1 if the path is unsafe (empty, root, or outside allowed prefixes).
guard_path() {
    local path="$1"
    local label="${2:-path}"

    # Reject empty paths
    if [ -z "$path" ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label is empty" >> "$DEBUG_LOG"
        return 1
    fi

    # Reject root or near-root paths
    if [ "$path" = "/" ] || [ "$path" = "/tmp" ] || [ "$path" = "/mnt" ] || [ "$path" = "/usr" ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label is a system root: $path" >> "$DEBUG_LOG"
        return 1
    fi

    # Allowlist of plugin-internal prefixes (work / cache / config / staging dirs).
    # These are hardcoded because they must always be writable and the user can't
    # relocate them.
    local allowed=0
    case "$path" in
        /tmp/unraid-aicliagents/zram_upper|/tmp/unraid-aicliagents/zram_upper/*) allowed=1 ;;
        /tmp/unraid-aicliagents|/tmp/unraid-aicliagents/*) allowed=1 ;;
        /usr/local/emhttp/plugins/unraid-aicliagents|/usr/local/emhttp/plugins/unraid-aicliagents/*) allowed=1 ;;
        /boot/config/plugins/unraid-aicliagents|/boot/config/plugins/unraid-aicliagents/*) allowed=1 ;;
    esac

    # User-configurable persistence paths under /mnt/. Allow any pool except the
    # mounts that aren't valid persistence roots. Rejecting only the explicit
    # system mounts means Unraid user-pools (e.g. /mnt/cache, /mnt/cache_nvme,
    # /mnt/scratch_old, /mnt/zfs_pool, custom names from disks.ini) all work.
    # S-02 (#1352): /mnt/disks and /mnt/addons are real Unassigned Devices mount
    # roots (NOT tmpfs — the old comment was wrong); /mnt/addons is the UD-blessed
    # path for plugin-owned devices. A sub-directory UNDER a mounted UD device is a
    # valid persist target; the device mount point itself (/mnt/disks/<label>) and
    # the bare roots are not — only a sub-path is useful, and requiring one avoids
    # accepting the mount-point parents as target roots. /mnt/remotes (network
    # shares) and /mnt/rootshare (Unraid root export) stay denied for persistence.
    if [ "$allowed" -ne 1 ]; then
        case "$path" in
            /mnt/disks/*/*)                 allowed=1 ;;  # UD block device sub-dir: /mnt/disks/<label>/<sub>
            /mnt/addons/*/*)                allowed=1 ;;  # UD blessed plugin-owned device sub-dir
            /mnt/disks|/mnt/disks/*)        : ;;  # bare UD root or device mount point — not a persist root
            /mnt/addons|/mnt/addons/*)      : ;;  # bare UD root or device mount point — not a persist root
            /mnt/remotes|/mnt/remotes/*)    : ;;  # network shares (CIFS/NFS) — refused for persistence
            /mnt/rootshare|/mnt/rootshare/*) : ;; # Unraid root export — not a user data path
            /mnt/*/*)                       allowed=1 ;;  # any pool: /mnt/<name>/<sub>
            /mnt/disk[0-9]*)                allowed=1 ;;  # array disks: /mnt/disk1, /mnt/disk2, ...
        esac
    fi

    if [ "$allowed" -ne 1 ]; then
        echo "[$(get_ts)] [ERR!] [guard_path] $label outside allowed prefixes: $path" >> "$DEBUG_LOG"
        return 1
    fi

    return 0
}

# _durable_fstype_ok <fstype> -> 0 if the fstype is a DURABLE backing store (survives
# reboot), 1 if VOLATILE (RAM/overlay). An unknown fstype errs DURABLE (return 0) so a
# new/exotic REAL disk is never wrongly refused. Pure (no findmnt) → unit-testable.
# #1317: zfs is durable (Unraid ZFS-backed /boot + ZFS pools) — it was falling through
# to the "unknown fstype -> treating as durable" warn arm.
_durable_fstype_ok() {
    case "${1:-}" in
        ext4|xfs|btrfs|zfs|vfat|exfat|f2fs|ntfs|fuseblk) return 0 ;;
        tmpfs|ramfs|devtmpfs|overlay|zram|squashfs)      return 1 ;;
        *)                                               return 0 ;;
    esac
}

# _assert_persist_durable: Validate that a persistence path is on a durable filesystem.
# Uses findmnt to resolve the actual fstype — immune to tmpfs bind-mounts that look like
# valid paths. Spec #341.
# Usage: _assert_persist_durable "/some/persist/path"
# Returns 1 (and logs) if non-durable or findmnt fails; caller must exit on failure.
_assert_persist_durable() {
    local path="$1"
    if ! command -v findmnt >/dev/null 2>&1; then
        echo "[$(get_ts)] [ERR!] [_assert_persist_durable] findmnt not found — cannot validate fstype for: $path" >> "$DEBUG_LOG"
        return 1
    fi
    local fstype
    fstype=$(findmnt --noheadings --output FSTYPE --target "$path" 2>/dev/null) || {
        echo "[$(get_ts)] [ERR!] [_assert_persist_durable] findmnt failed or path not mounted: $path" >> "$DEBUG_LOG"
        return 1
    }
    if _durable_fstype_ok "$fstype"; then
        # Keep the observability warn for genuinely-UNKNOWN fstypes (known durable ones
        # incl. zfs are silent now).
        case "$fstype" in
            ext4|xfs|btrfs|zfs|vfat|exfat|f2fs|ntfs|fuseblk) ;;
            *) echo "[$(get_ts)] [WARN] [_assert_persist_durable] unknown fstype '$fstype' for '$path' — treating as durable" >> "$DEBUG_LOG" ;;
        esac
        return 0
    fi
    echo "[$(get_ts)] [ERR!] [_assert_persist_durable] path '$path' is on $fstype (non-durable) — refused" >> "$DEBUG_LOG"
    return 1
}

# ---------------------------------------------------------------------------
# S-09 (#1352): FAT32 per-file size-cap preflight. FAT32 (vfat) caps a single
# file at 4,294,967,295 bytes — a bake/consolidate whose output squashfs would
# exceed it fails mid-mksquashfs with a confusing write error. Check BEFORE the
# bake: the cap is derived from the persist path's REAL fstype via findmnt —
# never from a path prefix.
FAT32_MAX_FILE_BYTES=4294967295

# _fat32_cap_check <persist> <src_dir> -> 0 ok / 1 over-cap (only ever 1 when the
# persist fstype is vfat AND the projected size is within 5% of the cap; the
# squashfs output compresses, so a du within 5% of the raw cap is a hard danger
# signal, not a borderline case). Sets _FAT32_PROJECTED_BYTES for the caller's
# diagnostics. AICLI_ITEST_PERSIST_FSTYPE forces the fstype (test hook, mirrors
# the AICLI_PROC_MOUNTS precedent) so the guard is unit-testable without vfat.
_fat32_cap_check() {
    local persist="${1:-}" src="${2:-}" fst proj cap
    _FAT32_PROJECTED_BYTES=0
    fst="${AICLI_ITEST_PERSIST_FSTYPE:-$(findmnt --noheadings --output FSTYPE --target "$persist" 2>/dev/null || echo '')}"
    [ "$fst" = "vfat" ] || return 0
    [ -d "$src" ] || return 0
    proj=$(du -sb "$src" 2>/dev/null | awk '{print $1}')
    case "$proj" in ''|*[!0-9]*) proj=0 ;; esac
    _FAT32_PROJECTED_BYTES="$proj"
    cap=$(( FAT32_MAX_FILE_BYTES * 95 / 100 ))   # refuse within 5% of the cap
    [ "$proj" -lt "$cap" ]
}

# _fat32_cap_refuse <type> <id> <component> — the shared exit-4 refusal tail for
# the FAT32 cap preflight: defer-reason marker (fat32_size_cap), lifecycle event,
# dynamix notification. Caller exits 4 (precondition failed) afterwards.
_fat32_cap_refuse() {
    local _type="${1:-}" _id="${2:-}" _component="${3:-storage}"
    write_defer_reason "$_type" "$_id" "fat32_size_cap"
    lifecycle_log "error" "$_component" "fat32_size_cap" \
        "{\"type\":\"$_type\",\"id\":\"$_id\",\"projected_bytes\":${_FAT32_PROJECTED_BYTES:-0},\"cap_bytes\":$FAT32_MAX_FILE_BYTES}" 2>/dev/null || true
    if [ -x /usr/local/emhttp/plugins/dynamix/scripts/notify ]; then
        /usr/local/emhttp/plugins/dynamix/scripts/notify -i warning \
            -s "AICliAgents: FAT32 file size limit" \
            -d "Home directory approaching FAT32 file size limit — recommend graduating to a POSIX pool (see Storage tab)." \
            2>/dev/null || true
    fi
    return 0
}

# check_disk_space: Ensure sufficient space before writing.
# Usage: check_disk_space "/target/path" <required_mb>
# Returns 1 if insufficient space.
check_disk_space() {
    local target_path="$1"
    local required_mb="${2:-100}"

    # Get available space in MB on the filesystem containing target_path
    local avail_mb
    avail_mb=$(df -Pm "$(dirname "$target_path")" 2>/dev/null | awk 'NR==2 {print $4}')

    if [ -z "$avail_mb" ]; then
        echo "[$(get_ts)] [WARN] [check_disk_space] Cannot determine free space for $target_path" >> "$DEBUG_LOG"
        return 0
    fi

    if [ "$avail_mb" -lt "$required_mb" ]; then
        echo "[$(get_ts)] [ERR!] [check_disk_space] Only ${avail_mb}MB free on $(dirname "$target_path"), need ${required_mb}MB" >> "$DEBUG_LOG"
        return 1
    fi

    return 0
}

# ---------------------------------------------------------------------------
# Layer identity & ordering (WP: D01/D02/D03 fix — monotonic per-entity seq).
#
# Canonical layer name (current writer):
#   ${type}_${id}_${kind}_${seq10}_${dt}.sqsh
#     kind  = delta | consolidated
#     seq10 = 10-digit zero-padded per-entity monotonic counter (PRIMARY identity
#             + sort key) — immune to wall-clock collisions / NTP step-back.
#     dt    = YYYYMMDDTHHMMSSZ (kept human-readable; secondary sort tiebreak).
#
# Legacy names (still valid lowers; never produced by the current writer) carry
# NO seq and are treated as seq 0 so they always sort BELOW any seq>=1 layer:
#   ${type}_${id}_delta_${dt}.sqsh          (ISO, pre-seq)
#   ${type}_${id}_consolidated_${dt}.sqsh   (ISO, pre-seq)
#   ${type}_${id}_delta_${epoch}.sqsh       (legacy epoch)
#   ${type}_${id}_v${epoch}_vol1.sqsh       (legacy consolidated)
#
# These helpers are the SINGLE SOURCE of layer discovery/ordering — mount_stack.sh,
# storagectl.sh and atomic_write_layer.sh all call them so naming + sort never diverge.

# _layer_parse_seq <name-or-path> -> integer seq (legacy / unparseable = 0).
_layer_parse_seq() {
    local bn seq
    bn="$(basename -- "$1" .sqsh)"
    # Only the new format carries a 10-digit seq between kind and dt.
    seq="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{10})_[0-9]{8}T[0-9]{6}Z$/\2/p')"
    # 10#-prefix forces base-10 (avoid octal on leading zeros); guard empty -> 0.
    printf '%d' "$((10#${seq:-0}))"
}

# _layer_sort_key <name-or-path> -> "<seq10> <ts>" — newest-first via `LC_ALL=C sort -r`.
# seq dominates, ts (dt/epoch) breaks ties; both fixed-shape so a byte-order reverse
# sort yields seq-desc then dt-desc.
_layer_sort_key() {
    local bn seq ts
    bn="$(basename -- "$1" .sqsh)"
    seq="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{10})_[0-9]{8}T[0-9]{6}Z$/\2/p')"
    if [ -n "$seq" ]; then
        ts="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_[0-9]{10}_([0-9]{8}T[0-9]{6}Z)$/\2/p')"
    else
        seq="0000000000"
        ts="$(printf '%s' "$bn" | sed -n -E 's/.*_(delta|consolidated)_([0-9]{8}T[0-9]{6}Z)$/\2/p')"
        [ -z "$ts" ] && ts="$(printf '%s' "$bn" | sed -n -E 's/.*_delta_([0-9]{10,})$/\1/p')"
        [ -z "$ts" ] && ts="$(printf '%s' "$bn" | sed -n -E 's/.*_v([0-9]{10,})_vol1$/\1/p')"
        [ -z "$ts" ] && ts="00000000T000000Z"
    fi
    printf '%s %s' "$seq" "$ts"
}

# _layer_next_seq <persist> <type> <id> -> next per-entity seq (max existing + 1).
# Fresh OR all-legacy (seq 0) entity -> 1, so the first new-format layer sorts above
# any pre-seq layers. Callers hold the per-entity bake lock, so this scan is race-free.
_layer_next_seq() {
    local persist="$1" type="$2" id="$3" max=0 f s
    shopt -s nullglob
    for f in "$persist"/"${type}_${id}_"*.sqsh; do
        [ -e "$f" ] || continue
        s="$(_layer_parse_seq "$f")"
        [ "$s" -gt "$max" ] && max="$s"
    done
    shopt -u nullglob
    printf '%d' "$((max + 1))"
}

# _layer_discover_sorted <persist> <type> <id> -> newest-first FULL PATHS, one/line.
# Single source of discovery ordering (seq desc, then dt desc). A tab separates the
# sort key from the path so `cut -f2-` recovers the path verbatim (paths have no tabs).
_layer_discover_sorted() {
    local persist="$1" type="$2" id="$3" f
    shopt -s nullglob
    for f in "$persist"/"${type}_${id}_"*.sqsh; do
        [ -e "$f" ] || continue
        printf '%s\t%s\n' "$(_layer_sort_key "$f")" "$f"
    done | LC_ALL=C sort -r | cut -f2-
    shopt -u nullglob
}

# ---------------------------------------------------------------------------
# Phase 5: homes-only consolidate policy constants + the effective-MAX helper.
#
# Consolidation is the most expensive + most data-loss-prone storage op, so for
# HOMES it should fire only when absolutely necessary: when the layer stack nears
# the overlay ceiling, or persist is under space pressure, or the user triggers it
# manually. The verdict is computed in storagectl `status` (so it's deterministically
# integration-testable); these constants + helper are the single source of its bounds.
#
# Constants are MEASURED, not guessed (probe on .4, 2026-05-30 — see
# docs/specs/PHASE5_STORAGECTL_DISPATCHER.md "The MAX setting"): up to 45 prod-shaped
# layers mounted with no kernel ceiling and ~2 ms (flat) overlay assembly; the
# `lowerdir=` option string grows ~83 B/layer (3382 B at k=40, under one 4096-B page).
DEFAULT_MAX_LAYERS=30          # home layer ceiling default; consolidate triggers at -2 (28)
CONSOLIDATE_FLOOR=4            # clamp floor (keeps max-2 >= 2)
CONSOLIDATE_HARD_CEILING=40    # clamp ceiling (option string ~3382 B, conservatively < 1 page)
# SCRATCH_MARGIN — bytes of headroom the space-pressure rule requires beyond the
# summed layer sizes (consolidated tempfile + verify scratch + fragmentation). 200 MB.
SCRATCH_MARGIN=$((200 * 1024 * 1024))

# _consolidate_max_layers -> effective home overlay layer ceiling.
# Precedence: AICLI_CONSOLIDATE_MAX_LAYERS env (test / emergency override, mirrors
# the AICLI_ITEST_GUARD test-hook precedent) > cfg key consolidate_max_layers >
# DEFAULT_MAX_LAYERS. Always clamped to [CONSOLIDATE_FLOOR, CONSOLIDATE_HARD_CEILING].
# Self-contained cfg read (common.sh may be sourced WITHOUT resolve_paths.sh, so it
# does not rely on _rp_read_cfg).
_consolidate_max_layers() {
    local raw="" cfg="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
    if [ -n "${AICLI_CONSOLIDATE_MAX_LAYERS:-}" ]; then
        raw="$AICLI_CONSOLIDATE_MAX_LAYERS"
    elif [ -f "$cfg" ]; then
        raw="$(grep -oP '^consolidate_max_layers="?\K[0-9]+' "$cfg" 2>/dev/null | head -1)"
    fi
    # Non-numeric / empty -> default.
    case "$raw" in
        ''|*[!0-9]*) raw="$DEFAULT_MAX_LAYERS" ;;
    esac
    # Clamp to the measured bounds.
    [ "$raw" -lt "$CONSOLIDATE_FLOOR" ] && raw="$CONSOLIDATE_FLOOR"
    [ "$raw" -gt "$CONSOLIDATE_HARD_CEILING" ] && raw="$CONSOLIDATE_HARD_CEILING"
    printf '%d' "$raw"
}

# _entity_paths <type> <id> <persist> — single source of the upper/work derivation +
# mount-point template shared by the trio (now storage_ops.sh) and storagectl. Sets
# globals: UPPER_DIR, WORK_DIR, MNT_POINT, ENTITY_UPPER_MODE.
# #1322: the zram-vs-disk upper-mode is now the GENUINE device test
# (entity_upper_mode -> backend_for: removable/USB → zram off Flash; durable → disk),
# replacing the old vfat-fstype proxy (a vfat-formatted SSD is not a wear-limited
# stick). Falls back to the legacy fstype proxy only if detect_backend is somehow
# unavailable. PURE path computation — no dir creation, no zram init (callers do those
# side-effects). Must keep bake/consolidate writing from the same upper a mount reads.
_entity_paths() {
    local _ep_type="$1" _ep_id="$2" _ep_persist="$3" _ep_mode
    if declare -f entity_upper_mode >/dev/null 2>&1; then
        _ep_mode="$(entity_upper_mode "$_ep_persist")"
    else
        local _ep_fst; _ep_fst=$(findmnt --noheadings --output FSTYPE --target "$_ep_persist" 2>/dev/null || echo '')
        { [ "$_ep_fst" = "vfat" ] || [ -z "$_ep_fst" ]; } && _ep_mode="zram" || _ep_mode="disk"
    fi
    if [ "$_ep_mode" = "zram" ]; then
        ENTITY_UPPER_MODE="zram"
        UPPER_DIR="$ZRAM_BASE/${_ep_type}s/$_ep_id/upper"
        WORK_DIR="$ZRAM_BASE/${_ep_type}s/$_ep_id/work"
    else
        ENTITY_UPPER_MODE="disk"
        UPPER_DIR="$_ep_persist/_upper/${_ep_type}s/$_ep_id"
        WORK_DIR="$_ep_persist/_work/${_ep_type}s/$_ep_id"
    fi
    if [ "$_ep_type" = "home" ]; then
        MNT_POINT="/tmp/unraid-aicliagents/work/$_ep_id/home"
    else
        MNT_POINT="/usr/local/emhttp/plugins/unraid-aicliagents/agents/$_ep_id"
    fi
}

# home_mount_in_use <mount_point>
# True (0) if a LIVE INTERACTIVE agent/terminal session is using this home mount
# — i.e. one a umount/remount would disrupt with ENOENT. Used to gate the
# post-bake reclaim (refresh + ZRAM cleanup) so it never remounts under a live
# session, only when the home is genuinely idle.
#
# WHY NOT a bare `HOME=<mount>` environ scan (the obvious approach, and what an
# earlier cut of this did): the plugin's OWN permanent daemons run with
# HOME=<mount> for the plugin's whole lifetime — the per-user session
# `dbus-daemon --session` and the `secret-service-daemon` (its keyring store
# lives under <mount>/.local/share). A HOME= scan matches those, so the home
# would read "busy" FOREVER and reclaim would never run (zram never reclaimed).
# Orphaned detached `tmux` from an unreaped close has the same effect. Neither is
# an interactive session a user is connected to.
#
# WHY ttyd: every interactive workspace/terminal session is fronted by a live
# `ttyd` whose child env (on its argv: `... env AICLI_HOME=<mount> ... aicli-shell.sh`)
# carries AICLI_HOME=<mount>. ttyd presence = a user is actually connected and the
# agent may touch HOME at any moment (e.g. claude's mkdir ~/.claude/session-env/
# <uuid>) — exactly the op that ENOENTs in the unmounted window. When all such
# sessions close, no ttyd carries this mount, so reclaim proceeds (daemons persist
# but only touch HOME rarely; that residual matches pre-fix behaviour).
#
# fuser -sm is kept as a fast path for any process holding a real open fd / cwd /
# exe on the fs (covers agent overlays and any directly-held handle).
#
# Best-effort: runs as root. Transient PIDs vanishing mid-scan are ignored.
home_mount_in_use() {
    local mnt="$1"
    [ -n "$mnt" ] || return 1
    # Fast path: any open fd / cwd / exe / mmap physically on the fs.
    fuser -sm "$mnt" 2>/dev/null && return 0
    # Live interactive session: a ttyd whose argv carries AICLI_HOME=<mount>.
    local _pid
    for _pid in $(pgrep -x ttyd 2>/dev/null); do
        if tr '\0' '\n' < "/proc/$_pid/cmdline" 2>/dev/null | grep -qxF "AICLI_HOME=$mnt"; then
            return 0
        fi
    done
    return 1
}

# WP #1309 / ADR 0001: the busy-arbiter for the overlay-remount chokepoint.
#
# _proc_mounts_path -> the /proc/mounts source. Overridable via AICLI_PROC_MOUNTS
# (a TEST hook ONLY — mirrors the AICLI_ITEST_GUARD / AICLI_CONSOLIDATE_MAX_LAYERS
# precedent) so the arbiter below is unit-testable without a real kernel mount.
_proc_mounts_path() { printf '%s' "${AICLI_PROC_MOUNTS:-/proc/mounts}"; }

# _overlay_present_at <mnt> -> 0 (true) IFF /proc/mounts shows an overlay mounted
# at exactly <mnt>. Mirrors PHP isHomeMountHealthy's "^overlay <mnt> overlay" probe.
_overlay_present_at() {
    local mnt="${1:-}"
    [ -n "$mnt" ] || return 1
    awk -v m="$mnt" '$2==m && $3=="overlay"{f=1} END{exit f?0:1}' "$(_proc_mounts_path)" 2>/dev/null
}

# _mount_teardown_arbiter <mnt> — THE single busy-arbiter for the overlay
# (re)mount chokepoint (WP #1309; spec docs/specs/OP_MOUNT_BUSY_REMOUNT_SAFETY.md).
# Safely release an existing mount before a fresh overlay bind on the SAME
# upper/work, and NEVER do `umount -l` followed by an immediate rebind — that is
# the copy-up-poison path (a lazy umount only detaches from the namespace and
# defers releasing upper/work, so a fresh overlay double-binds the same upper →
# kernel "undefined behaviour", new-file create returns ENOENT).
#
# Uses ONLY `mountpoint`, a single SYNCHRONOUS `umount`, and a /proc/mounts
# overlay probe, so it is fully PATH-stubbable for unit tests. Return codes map
# straight onto storagectl's exit contract:
#   0  released, or never mounted              -> caller binds a fresh overlay
#   2  busy (umount refused) + live overlay    -> caller DEFERS (exit 2); the
#      upper holds all the data, only the lower refresh waits for the next idle
#   1  busy + NOT a healthy overlay (phantom)  -> caller errors (exit 1)
_mount_teardown_arbiter() {
    local mnt="${1:-}"
    [ -n "$mnt" ] || return 0
    mountpoint -q "$mnt" 2>/dev/null || return 0     # not mounted -> safe to bind fresh
    if umount "$mnt" 2>/dev/null; then               # REAL (non-lazy) umount ONLY
        return 0                                      # released -> safe to bind fresh
    fi
    # umount refused: the mount is BUSY. NEVER lazy-umount-then-rebind.
    if _overlay_present_at "$mnt"; then
        return 2                                      # keep the live overlay; defer the refresh
    fi
    return 1                                          # busy + phantom -> unsafe to remount
}

# WP #1263 (#7): canonical per-entity overlay-mount-op lock path.
#
# Every overlay (re)mount of an entity routes through op_mount — the launch path
# (StorageMountService::ensureHomeMounted -> storagectl mount -> op_mount), the
# post-bake reclaim refresh (op_bake -> op_mount), and the post-consolidate
# remount (op_consolidate -> op_mount) all call it. Taking this flock INSIDE
# op_mount therefore serialises a launch's stack assembly against a concurrent
# reclaim/consolidate remount, closing the residual race that the
# home_mount_in_use defer leaves open: a launch begins before its ttyd exists, so
# the home momentarily reads "idle" and a supervisor reclaim could umount/remount
# the overlay out from under the assembling launch.
#
# It is a SEPARATE lock from the per-entity bake lock
# (/var/run/aicli-bake-<type>-<id>.lock): the bake lock guards the whole
# squashfs write, which is safe during a live session and must NOT block a launch
# for minutes — only the brief remount needs mutual exclusion. It is also
# separate from PHP's home_mount lock (which serialises PHP-vs-PHP launches);
# making op_mount block on THAT file would deadlock (PHP holds it across the
# op_mount exec). Sanitisation matches storage_ops.sh _LOCK_ID ([^a-zA-Z0-9_-] -> _).
mount_op_lock_path() {
    local _type="${1:-home}" _id="${2:-}"
    local _safe="${_id//[^a-zA-Z0-9_-]/_}"
    echo "/var/run/aicli-mount-op-${_type}-${_safe}.lock"
}

# WP #1278 (#3): consolidate lowerdir-completeness guard helpers.
#
# op_consolidate bakes the consolidated layer from the MERGED overlay view, then
# DELETES the old delta layers. If the overlay it reads is "short" — fewer
# lowerdirs mounted than there are .sqsh layers on disk — the consolidated layer
# omits the missing layers' data and the delete destroys those deltas
# permanently (the May-29 Tower vector: the consolidated was missing the user's
# conversations). WP #1246 added a pre-consolidate mount refresh; this pair is
# the assertion backstop so a still-short mount aborts BEFORE any delete.

# _count_lowerdirs_in_opts <overlay-options-string> -> number of lowerdir entries
# (0 if there is no lowerdir token). The options string is field 4 of a
# /proc/mounts overlay line: lowerdir is a colon-joined path list, comma-
# separated from upperdir/workdir. Our loop-mount lower paths never contain a
# comma, so [^,] safely delimits the lowerdir value. ERE only — no PCRE.
_count_lowerdirs_in_opts() {
    local opts="${1:-}" lower
    lower=$(printf '%s' "$opts" | sed -n -E 's/.*lowerdir=([^,]*).*/\1/p')
    [ -n "$lower" ] || { printf '0'; return 0; }
    printf '%s' "$lower" | awk -F: '{print NF}'
}

# _overlay_lowerdir_string <mnt_point> -> the options field of the overlay
# mounted at <mnt_point> ('' if none). Reads /proc/mounts directly: findmnt can
# truncate very long option strings, and /proc/mounts is always present on the
# live box. The LAST matching overlay line wins (the most recent mount), so a
# refresh's new mount is the one measured.
_overlay_lowerdir_string() {
    local mnt="${1:-}"
    [ -n "$mnt" ] || return 0
    awk -v m="$mnt" '$2==m && $3=="overlay"{o=$4} END{if(o!="")print o}' /proc/mounts 2>/dev/null
}

# _mounted_lower_count <mnt_point> -> number of lowerdirs in the live overlay at
# <mnt_point> (0 if not an overlay mount). Convenience composition of the two
# helpers above for callers that just want the count.
_mounted_lower_count() {
    _count_lowerdirs_in_opts "$(_overlay_lowerdir_string "${1:-}")"
}

# WP #922: Flash-backed failure-snapshot helpers.
#
# When a storage script (consolidate_layers.sh, commit_stack.sh, …) exits
# non-zero, capture the last 50 lines of DEBUG_LOG to a Flash-backed file so
# the cause survives /tmp rotation. Also drop a short stderr-tail snippet the
# supervisor can splice into its lifecycle event for at-a-glance diagnosis
# without opening the per-failure file.
#
# Flash wear footprint: ~5 KB per failure, capped at 50 files. Trivial.
# #1254: AICLI_FAILURES_DIR redirects failure snapshots off USB flash for the L3.5
# suite (test-only; unset in production -> the flash path).
FAILURE_DIR="${AICLI_FAILURES_DIR:-/boot/config/plugins/unraid-aicliagents/failures}"

# snapshot_failure <type> <id> <exit_code> <source>
# Best-effort — never fails, always returns 0.
snapshot_failure() {
    local f_type="${1:-unknown}"
    local f_id="${2:-unknown}"
    local f_exit="${3:-1}"
    local f_source="${4:-storage_script}"
    local ts
    ts=$(date -u '+%Y%m%dT%H%M%SZ' 2>/dev/null || date '+%Y%m%dT%H%M%S')

    mkdir -p "$FAILURE_DIR" 2>/dev/null || return 0

    local safe_type safe_id
    safe_type=$(printf '%s' "$f_type" | tr -c 'A-Za-z0-9_.-' '_')
    safe_id=$(printf '%s' "$f_id"   | tr -c 'A-Za-z0-9_.-' '_')

    local out="$FAILURE_DIR/${f_source}_${safe_type}_${safe_id}_${ts}.log"
    {
        echo "# AICliAgents failure snapshot"
        echo "# source: $f_source"
        echo "# entity: $f_type/$f_id"
        echo "# exit_code: $f_exit"
        echo "# captured_at: $(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date)"
        echo "# debug.log tail (last 50 lines):"
        echo "# ----"
        [ -f "$DEBUG_LOG" ] && tail -n 50 "$DEBUG_LOG" 2>/dev/null
    } > "$out" 2>/dev/null || true

    # stderr_tail for the supervisor's lifecycle event — per-entity so
    # concurrent failures don't collide.
    local tail_path="/tmp/unraid-aicliagents/.stderr_tail_${safe_type}_${safe_id}.txt"
    [ -f "$DEBUG_LOG" ] && tail -n 10 "$DEBUG_LOG" 2>/dev/null > "$tail_path" 2>/dev/null || true

    # Rotate the failures dir — keep the 50 most-recent snapshots; older ones
    # are unlinked. Flash-cheap, avoids unbounded growth.
    ls -1t "$FAILURE_DIR" 2>/dev/null | tail -n +51 | while read -r oldfile; do
        [ -n "$oldfile" ] && rm -f "$FAILURE_DIR/$oldfile" 2>/dev/null || true
    done

    return 0
}

# install_failure_trap <type> <id> <source>
# Registers an EXIT trap that calls snapshot_failure on non-zero exit, except
# for exit code 2 (which means "deferred — busy", not a failure). Call near
# the top of any storage script that wants the snapshot behaviour.
install_failure_trap() {
    local t_type="${1:-unknown}"
    local t_id="${2:-unknown}"
    local t_source="${3:-storage_script}"
    # shellcheck disable=SC2064 — intentional eager expansion of $t_* here.
    trap "ec=\$?; if [ \$ec -ne 0 ] && [ \$ec -ne 2 ]; then snapshot_failure '$t_type' '$t_id' \$ec '$t_source'; fi" EXIT
}

# ----------------------------------------------------------------------------
# WP #935: SQLite live-backup helpers + selective UPPER cleanup.
# See docs/specs/CONSOLIDATE_LIVE_BACKUP.md for the design.
# ----------------------------------------------------------------------------

# detect_sqlite_dbs <root>
# Echoes one absolute path per line for every SQLite-format-3 file found under
# $root. M1 fix (v2026.05.18.08): magic-byte sniffs EVERY regular file rather
# than only those matching *.db/*.sqlite/*.sqlite3 — third-party agents may use
# non-standard extensions (.db3, .s3db, no extension). The sniff cost is one
# 16-byte read per file; UPPER is typically <100K files on tmpfs, so well under
# a second. We still skip files >2GB (SQLite header is always within the first
# 16 bytes and large non-DB files like archives would be wasteful to sniff).
# WAL/SHM siblings (*.db-wal, *.db-shm) intentionally NOT included — they have
# their own magic bytes ("ZP" for WAL, internal for SHM) and the caller's
# build_mksquashfs_sqlite_excludes generates -wal/-shm exclusions from each
# parent .db automatically.
detect_sqlite_dbs() {
    local root="$1"
    [ -d "$root" ] || return 0
    find "$root" -type f -size -2147483648c 2>/dev/null \
        | while IFS= read -r f; do
            if head -c 16 "$f" 2>/dev/null | grep -q "SQLite format 3"; then
                printf '%s\n' "$f"
            fi
        done
}

# sqlite_backup_all <root> <staging> <db-paths...>
# For each db path, runs `sqlite3 X ".backup STAGING/relative/X"`. Online Backup
# API works on a live DB without preventing concurrent writers. Returns:
#   0 — all DBs backed up successfully
#   2 — at least one backup failed (locked / disk full / timeout); caller should defer
sqlite_backup_all() {
    local root="$1"; shift
    local staging="$1"; shift
    if ! command -v sqlite3 >/dev/null 2>&1; then
        echo "[sqlite_backup_all] WARN: sqlite3 not on PATH; cannot back up SQLite DBs" >&2
        return 2
    fi
    mkdir -p "$staging" 2>/dev/null || return 1
    local db rel dest
    for db in "$@"; do
        [ -f "$db" ] || continue
        rel="${db#$root/}"
        dest="$staging/$rel"
        mkdir -p "$(dirname "$dest")" 2>/dev/null
        # 30 s timeout — if a writer holds an exclusive lock that long, defer.
        if ! timeout 30 sqlite3 "$db" ".timeout 30000
.backup '$dest'" 2>/dev/null; then
            echo "[sqlite_backup_all] backup failed: $db (locked / disk full / timeout)" >&2
            return 2
        fi
    done
    return 0
}

# selective_upper_cleanup <upper_dir> <marker_file> [confirmed_manifest]
# Wipes files in upper_dir that satisfy ALL of:
#   (a) mtime not newer than the marker (no writes during bake), AND
#   (b) no process holds an open write fd to the file, AND
#   (c) WP #1277 — if a confirmed_manifest is supplied, the file is PROVEN to be
#       in the just-baked layer (its absolute path appears in the manifest).
# Prints a JSON status line to stdout for the caller to inline into a
# lifecycle event. Best-effort — never fails the caller.
#
# WP #1277 (bake-confirmed reclaim — THE generic data-loss fix): the optional
# third argument is a file listing the absolute upper paths the bake actually
# captured (atomic_write_layer emits the baked file list during its verify RO-
# mount; op_bake maps those relative paths onto UPPER_DIR and passes the result
# here). When supplied, the wipe set is intersected with it, so a CLOSED file
# (no open write fd, mtime ≤ marker) that the bake FAILED to capture — stale
# lowerdir, a busy-cooldown-skipped bake, or a silent capture failure — is never
# reclaimed. It lingers harmlessly in zram until a later bake captures it. This
# protects every agent's durable store generically, without depending on the
# per-agent chat-store allowlist below (which stays as belt-and-braces). When
# the argument is omitted (legacy callers / agent bakes that don't thread it),
# behaviour is unchanged: wipe set = candidates − excludes.
selective_upper_cleanup() {
    local upper="$1"
    local marker="$2"
    local confirmed="${3:-}"
    if [ ! -d "$upper" ] || [ ! -f "$marker" ]; then
        echo '{"wiped_bytes":0,"wiped_count":0,"residual_bytes":0,"residual_files":0}'
        return 0
    fi

    local tmpdir
    tmpdir=$(mktemp -d 2>/dev/null) || tmpdir="/tmp/.suc-$$"
    mkdir -p "$tmpdir" 2>/dev/null

    # (a) Candidate set: files older-or-equal mtime to marker.
    find "$upper" -type f ! -newer "$marker" 2>/dev/null | sort -u > "$tmpdir/candidates"

    # (b1) SQLite-aware exclusion (C1 fix, v2026.05.18.08): SQLite WAL-mode
    # readers hold the .db open as O_RDONLY + mmap. Our /proc/*/fdinfo flags
    # check only catches write-mode fds, so a read-only mmap'd .db with old
    # mtime would otherwise be admitted to the wipe set. If sqlite_backup_all
    # deferred this cycle OR the append failed silently, the bake doesn't
    # contain the .db — wiping it would be permanent loss. Add every
    # detected SQLite .db (plus its -wal and -shm siblings) to excludes
    # unconditionally. This is defence in depth: the canonical preservation
    # path is the three-pass bake (detect → backup → append), but if anything
    # in that chain breaks, this stops the wipe.
    : > "$tmpdir/excludes"
    local _sqlite_db
    while IFS= read -r _sqlite_db; do
        [ -n "$_sqlite_db" ] || continue
        printf '%s\n' "$_sqlite_db" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-wal" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-shm" >> "$tmpdir/excludes"
        printf '%s\n' "${_sqlite_db}-journal" >> "$tmpdir/excludes"
    done < <(detect_sqlite_dbs "$upper" 2>/dev/null)

    # (b1.6) Chat/session-store defence-in-depth (DATA-LOSS guard).
    # Agent conversation history (Claude .jsonl transcripts, Antigravity .pb
    # conversations + index, gemini chat logs, factory/codex/pi sessions, kilo/
    # opencode prompt history) is durable USER state — the same class as SQLite
    # above and the .gemini/tmp chats WP #931 carved out of the prune. Once an
    # agent closes the file it has no open write fd, and with mtime <= marker it
    # would otherwise be admitted to the wipe set. If the just-run bake/consolidate
    # failed to durably capture it first (stale lowerdir, or a busy-cooldown
    # skipped/deferred bake), reclaiming it is PERMANENT loss — the bug that
    # destroyed a user's Claude + Antigravity conversations across an upgrade.
    # Protect these paths unconditionally; a never-reclaimed conversation merely
    # lingers in zram until a later bake captures it (KB-sized; negligible).
    # NOTE: when adding a new agent, add its conversation/session store here.
    while IFS= read -r _chat_f; do
        [ -n "$_chat_f" ] && printf '%s\n' "$_chat_f" >> "$tmpdir/excludes"
    done < <(find "$upper" -type f \( \
        -path '*/.claude/projects/*' -o \
        -path '*/.claude/sessions/*' -o \
        -path '*/.claude/history.jsonl' -o \
        -path '*/.gemini/antigravity-cli/conversations/*' -o \
        -path '*/.gemini/antigravity-cli/cache/last_conversations.json' -o \
        -path '*/.gemini/tmp/*/chats/*' -o \
        -path '*/.gemini/projects.json' -o \
        -path '*/.gemini/config/projects/*' -o \
        -path '*/.factory/sessions/*' -o \
        -path '*/.codex/sessions/*' -o \
        -path '*/.pi/agent/sessions/*' -o \
        -path '*/.local/state/*/prompt-history.jsonl' \
        \) 2>/dev/null)

    # (b2) Open-fd exclusion: files with an open write fd anywhere in /proc.
    # Walk /proc/*/fd, resolve each symlink, check fdinfo flags for write access.
    # flags is an octal string ending in 1 (O_WRONLY) or 2 (O_RDWR) means write.
    local fd_dir fd target pid_fdinfo flags last_char
    for fd_dir in /proc/[0-9]*/fd; do
        [ -d "$fd_dir" ] || continue
        for fd in "$fd_dir"/*; do
            [ -L "$fd" ] || continue
            target=$(readlink "$fd" 2>/dev/null) || continue
            case "$target" in
                "$upper"/*) ;;
                *) continue ;;
            esac
            pid_fdinfo="${fd_dir%/fd}/fdinfo/$(basename "$fd")"
            [ -f "$pid_fdinfo" ] || continue
            flags=$(awk '/^flags:/ {print $2}' "$pid_fdinfo" 2>/dev/null)
            # Octal flag's last digit: 0=O_RDONLY, 1=O_WRONLY, 2=O_RDWR, 3=O_RDWR|O_NONBLOCK, etc.
            last_char="${flags: -1}"
            case "$last_char" in
                1|2|3) printf '%s\n' "$target" >> "$tmpdir/excludes" ;;
            esac
        done
    done 2>/dev/null
    sort -u "$tmpdir/excludes" -o "$tmpdir/excludes" 2>/dev/null

    # Wipe set = candidates - excludes
    comm -23 "$tmpdir/candidates" "$tmpdir/excludes" > "$tmpdir/wipe" 2>/dev/null

    # (c) WP #1277 bake-confirmed intersection. When the caller supplies the
    # confirmed-baked manifest, restrict the wipe set to files PROVEN present in
    # the just-written layer: wipe = (candidates − excludes) ∩ confirmed. Both
    # inputs must be sorted for comm; `wipe` already is (comm -23 preserves order
    # over sorted candidates), so we only sort the manifest. An EMPTY manifest
    # (a bake that captured zero files) authorises NO wipe — absence of proof is
    # not proof of capture.
    if [ -n "$confirmed" ]; then
        sort -u "$confirmed" > "$tmpdir/confirmed.sorted" 2>/dev/null || : > "$tmpdir/confirmed.sorted"
        comm -12 "$tmpdir/wipe" "$tmpdir/confirmed.sorted" > "$tmpdir/wipe.confirmed" 2>/dev/null
        mv -f "$tmpdir/wipe.confirmed" "$tmpdir/wipe" 2>/dev/null
    fi

    local wiped_bytes=0 wiped_count=0 bytes
    while IFS= read -r f; do
        [ -n "$f" ] && [ -f "$f" ] || continue
        bytes=$(stat -c '%s' "$f" 2>/dev/null || echo 0)
        if rm -f "$f" 2>/dev/null; then
            wiped_bytes=$((wiped_bytes + bytes))
            wiped_count=$((wiped_count + 1))
        fi
    done < "$tmpdir/wipe"

    # Strip opaque xattr from newly-empty directories rather than deleting them.
    # Deleting an empty directory from the upper (even with -mindepth 1) causes
    # subsequent overlayfs copy-up from the squashfs lower to fail with ENOENT —
    # the same ENOENT seen in WP #1224 when $upper itself was deleted, now one
    # level down. By stripping trusted.overlay.opaque the directory becomes a
    # transparent scaffold: lower-layer content stays visible through it, and
    # new writes land directly in the upper without needing copy-up.
    find "$upper" -mindepth 1 -type d -empty 2>/dev/null | while IFS= read -r _d; do
        setfattr -x trusted.overlay.opaque "$_d" 2>/dev/null || true
        setfattr -x trusted.overlay.redirect "$_d" 2>/dev/null || true
    done

    local residual_bytes residual_files
    residual_bytes=$(du -sb "$upper" 2>/dev/null | awk '{print $1}')
    residual_bytes="${residual_bytes:-0}"
    residual_files=$(find "$upper" -type f 2>/dev/null | wc -l | tr -d ' ')
    residual_files="${residual_files:-0}"

    rm -rf "$tmpdir" 2>/dev/null

    printf '{"wiped_bytes":%d,"wiped_count":%d,"residual_bytes":%s,"residual_files":%s}\n' \
        "$wiped_bytes" "$wiped_count" "$residual_bytes" "$residual_files"
    return 0
}

# build_mksquashfs_sqlite_excludes <root> <db-paths...>
# Echoes the `-e <relpath>` arguments to pass to mksquashfs for excluding each
# SQLite DB and its WAL/SHM siblings. Caller splits the output on whitespace
# and passes to mksquashfs.
build_mksquashfs_sqlite_excludes() {
    local root="$1"; shift
    local db rel
    for db in "$@"; do
        rel="${db#$root/}"
        printf -- '-e %s -e %s -e %s ' "$rel" "${rel}-wal" "${rel}-shm"
    done
}

# build_mksquashfs_sqlite_sidecar_excludes <root> <db-paths...>
# Echoes `-e <relpath>-wal -e <relpath>-shm -e <relpath>-journal` arguments —
# the SIDECAR files only, NOT the .db itself. Used by the overlay-merge bake
# path (WP #1078): the .db is provided via the overlay upper from the
# sqlite3 .backup snapshot, so it doesn't need exclusion; the sidecars are
# transient and reconstructed by SQLite on next open.
build_mksquashfs_sqlite_sidecar_excludes() {
    local root="$1"; shift
    local db rel
    for db in "$@"; do
        rel="${db#$root/}"
        printf -- '-e %s -e %s -e %s ' "${rel}-wal" "${rel}-shm" "${rel}-journal"
    done
}

# bake_via_overlay_merge <type> <id> <persist_path> <lower_dir> <sqlite_stage> <kind> <db-paths...>
#
# Bakes a SquashFS layer from the merged view of <lower_dir> + <sqlite_stage>.
# Stdout: final basename on success. Stderr: progress + diagnostics.
# Returns 0 on success, non-zero on any failure (overlay mount, atomic_write_layer, umount).
#
# WP #1078 fix: replaces the broken two-pass `wide-bake + mksquashfs -append`
# protocol. mksquashfs's append mode does NOT merge new content into existing
# directories — it RENAMES them with _N suffix, stranding the SQLite backups
# at unreachable paths (.copilot_1/session-store.db etc.). This helper uses
# overlayfs to merge the lower (UPPER_DIR for commit, MNT_POINT for consolidate)
# with the SQLite backup stage, then bakes the merged view in ONE atomic pass.
# The .db sidecar files (-wal/-shm/-journal) are excluded — SQLite reconstructs
# them from the .db on next open.
#
# Caller must have already populated $sqlite_stage via sqlite_backup_all.
# Caller is responsible for `rm -rf "$sqlite_stage"` after this returns.
bake_via_overlay_merge() {
    local type="${1:-}"; shift
    local id="${1:-}"; shift
    local persist_path="${1:-}"; shift
    local lower_dir="${1:-}"; shift
    local sqlite_stage="${1:-}"; shift
    local kind="${1:-delta}"; shift
    # remaining positional args are the detected SQLite DB paths (absolute, under $lower_dir)

    if [ -z "$type" ] || [ -z "$id" ] || [ -z "$persist_path" ] || [ -z "$lower_dir" ] || [ -z "$sqlite_stage" ]; then
        echo "[bake_via_overlay_merge] ERROR: missing required arguments" >&2
        return 1
    fi
    if [ ! -d "$lower_dir" ] || [ ! -d "$sqlite_stage" ]; then
        echo "[bake_via_overlay_merge] ERROR: lower_dir or sqlite_stage missing" >&2
        return 1
    fi

    # Per-invocation working dirs on tmpfs (overlay requires upper + work on same fs).
    local merge_root="/tmp/unraid-aicliagents/.bake_merge_${type}_${id}_$$"
    local merge_work="$merge_root/work"
    local merge_out="$merge_root/merged"
    rm -rf "$merge_root" 2>/dev/null
    mkdir -p "$merge_work" "$merge_out" 2>/dev/null || {
        echo "[bake_via_overlay_merge] ERROR: cannot create merge dirs under $merge_root" >&2
        return 1
    }

    # Mount overlay: lower=lower_dir (read-only logically), upper=sqlite_stage (shadows
    # the live DBs at their original paths), work=fresh tmpfs dir.
    if ! mount -t overlay overlay \
        -o "lowerdir=${lower_dir},upperdir=${sqlite_stage},workdir=${merge_work}" \
        "$merge_out" 2>/dev/null; then
        echo "[bake_via_overlay_merge] ERROR: overlay mount failed: lower=$lower_dir upper=$sqlite_stage" >&2
        rm -rf "$merge_root" 2>/dev/null
        return 1
    fi

    # Build sidecar excludes (relative to lower_dir, which is the overlay's effective root).
    local sidecar_excludes
    # shellcheck disable=SC2086 — intentional word-split on positional args
    sidecar_excludes=$(build_mksquashfs_sqlite_sidecar_excludes "$lower_dir" "$@")

    # Compose final mksquashfs args. Preserve any caller-supplied MKSQUASHFS_ARGS
    # (e.g. compression override from supervisor), append sidecar excludes.
    local base_args="${MKSQUASHFS_ARGS:-${_AWL_DEFAULT_ARGS:--comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend}}"
    local final_args="$base_args $sidecar_excludes"

    local result=""
    if ! result=$(MKSQUASHFS_ARGS="$final_args" atomic_write_layer "$type" "$id" "$persist_path" "$merge_out" "$kind"); then
        umount "$merge_out" 2>/dev/null || umount -l "$merge_out" 2>/dev/null || true
        rm -rf "$merge_root" 2>/dev/null
        echo "[bake_via_overlay_merge] ERROR: atomic_write_layer failed" >&2
        return 1
    fi

    # Cleanup overlay. Lazy umount is the safety net for cases where a stray fd
    # held the merge_out path open during bake (shouldn't happen, but harmless).
    umount "$merge_out" 2>/dev/null || umount -l "$merge_out" 2>/dev/null || true
    rm -rf "$merge_root" 2>/dev/null

    printf '%s\n' "$result"
    return 0
}

# Canonical defer-reason vocabulary — the SINGLE source (ADR 0001 finding #5).
# Previously the strings were split across common.sh (write), storagectl.sh
# (read into JSON .defer_reason) and TaskService.php (user-facing message) with
# no shared definition. write_defer_reason validates against this set; storagectl
# surfaces the raw string; PHP TaskService maps it to a message. KEEP IN SYNC.
#   mount_busy                      — a live session / open fd holds the mount (mount, bake, consolidate)
#   busy_cooldown                   — home busy + within the per-session bake cooldown (op_bake)
#   sqlite_backup_deferred          — a SQLite .backup was locked / timed out (bake, consolidate)
#   consolidate_lowerdir_incomplete — live overlay short vs on-disk layers (op_consolidate, WP #1278)
#   bake_lock_held                  — consolidate found the per-entity bake lock held (bake wins)
#   bake_landed_during_consolidate  — a delta baked during the unlocked mksquashfs window
#   target_not_mounted              — persist path's backing mount absent (UD device may mount late) (op_mount, S-02 #1352)
#   fat32_size_cap                  — projected layer would exceed the FAT32 4 GiB per-file cap (bake/consolidate exit 4, S-09 #1352)
#   upper_not_empty                 — graduate found unflushed files in the upper after the flush+consolidate (op_graduate exit 2, S-10 #1354)
#   graduate_precondition           — graduate precondition failed (exit 4 — wrong device/engine/backend, no layers, or pt dir occupied) (op_graduate, S-10 #1354)
AICLI_DEFER_REASONS="mount_busy busy_cooldown sqlite_backup_deferred consolidate_lowerdir_incomplete bake_lock_held bake_landed_during_consolidate target_not_mounted fat32_size_cap upper_not_empty graduate_precondition"

# ---------------------------------------------------------------------------
# S-03 (#1352): defer-marker TTL. A defer-reason marker is a point-in-time
# diagnostic, not durable state — a marker older than the TTL is STALE (its op
# either retried since or was abandoned) and must be silently ignored by readers
# (storagectl _read_defer_reason, PHP FileStorage::peekDeferReason) and reaped by
# the supervisor's reconcile tick. Precedence mirrors _consolidate_max_layers:
# AICLI_DEFER_MARKER_TTL_H env (test hook) > cfg key defer_marker_ttl_h > 24.
_defer_marker_ttl_h() {
    local raw="" cfg="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"
    if [ -n "${AICLI_DEFER_MARKER_TTL_H:-}" ]; then
        raw="$AICLI_DEFER_MARKER_TTL_H"
    elif [ -f "$cfg" ]; then
        raw="$(grep -oP '^defer_marker_ttl_h="?\K[0-9]+' "$cfg" 2>/dev/null | head -1)"
    fi
    case "$raw" in
        ''|*[!0-9]*) raw=24 ;;
    esac
    [ "$raw" -lt 1 ] && raw=1
    printf '%d' "$raw"
}

# _defer_marker_fresh <marker_path> -> 0 (true) IFF the marker exists and its
# mtime is within the TTL. Readers treat a non-fresh marker as absent.
# (BSD-stat fallback only so the L2 unit also runs on a dev Mac; prod is GNU.)
_defer_marker_fresh() {
    local marker="${1:-}" mtime now ttl_s
    [ -n "$marker" ] && [ -f "$marker" ] || return 1
    mtime=$(stat -c '%Y' "$marker" 2>/dev/null || stat -f '%m' "$marker" 2>/dev/null) || return 1
    case "$mtime" in ''|*[!0-9]*) return 1 ;; esac
    now=$(date +%s)
    ttl_s=$(( $(_defer_marker_ttl_h) * 3600 ))
    [ $(( now - mtime )) -lt "$ttl_s" ]
}

# ---------------------------------------------------------------------------
# Write-ahead intent log (Epic #1310 #1320). A destructive storage op writes a
# fsync'd intent marker on the persist path BEFORE it starts (recording its plan:
# what it keeps, what it deletes) and clears it on success. A marker that SURVIVES
# means the op was interrupted; its recorded plan lets recovery distinguish an
# INTENTIONAL prune (a layer the op was replacing — safe) from a REAL loss (a
# layer that vanished with no intent — halt). This makes crash recovery
# unambiguous; the boot-integrity heuristics (#1314/#1319) remain the backstop.
_intent_path() { printf '%s/.aicli-intent-%s-%s.json' "${1%/}" "$2" "$3"; }   # persist type id

# write_intent <persist> <type> <id> <json> — atomic + fsync'd (tmp → sync → rename).
write_intent() {
    local p; p="$(_intent_path "$1" "$2" "$3")"
    { printf '%s' "$4" > "$p.tmp" && sync "$p.tmp" 2>/dev/null; mv -f "$p.tmp" "$p"; } 2>/dev/null || true
}
read_intent()  { cat "$(_intent_path "$1" "$2" "$3")" 2>/dev/null || true; }   # persist type id
clear_intent() { rm -f  "$(_intent_path "$1" "$2" "$3")" 2>/dev/null || true; }   # persist type id

# _intent_delete_segment <intent_json> -> echoes ONLY the contents of the intent's
# "delete":[ ... ] array (between the brackets), or empty if there is no delete
# array. F1 (WP#1325): membership tests MUST run against this segment, never the
# whole JSON — the kept consolidated layer's basename appears in the "keep" field
# quoted identically to the delete entries, so a whole-JSON substring match would
# mask the loss of the keep layer itself as an "intentional prune". Pure parameter
# expansion (no jq); layer basenames never contain '[' or ']'.
_intent_delete_segment() {
    case "$1" in
        *'"delete":['*) : ;;       # has a delete array
        *) return 0 ;;             # no delete plan -> empty (nothing intentional)
    esac
    local _seg="${1#*\"delete\":[}"   # drop everything up to & including "delete":[
    printf '%s' "${_seg%%]*}"          # keep up to the first closing ]
}

# intent_layer_is_intentional_prune <intent_json> <layer_basename> -> 0 (true) if
# the intent records this layer in its DELETE plan (its quoted basename appears in
# the "delete" array), i.e. its absence is an intentional prune, not data loss.
# Anchored to the delete segment so the "keep" layer can never be masked (F1).
intent_layer_is_intentional_prune() {
    case "$(_intent_delete_segment "$1")" in *"\"$2\""*) return 0 ;; *) return 1 ;; esac
}

# Test-only DETERMINISTIC SIGKILL hook (Epic #1310 Follow-on 4 L3.5). A no-op in
# production — fires only when AICLI_ITEST_SIGKILL_WINDOW matches <window>, which
# ONLY the integration harness's it_sigkill_mid_op sets. When it fires it signals
# the harness (touch the sentinel) that the op has REACHED this exact window, then
# sleeps so the harness can process-group-kill it deterministically — proving crash
# recovery at each window without racing a fast op. Guarded so it can never sleep on
# a real box (env unset → instant return 0).
_itest_sigkill_window() {
    [ -n "${AICLI_ITEST_SIGKILL_WINDOW:-}" ] || return 0
    [ "${AICLI_ITEST_SIGKILL_WINDOW}" = "${1:-}" ] || return 0
    [ -n "${AICLI_ITEST_SIGKILL_SENTINEL:-}" ] && : > "$AICLI_ITEST_SIGKILL_SENTINEL" 2>/dev/null || true
    sleep "${AICLI_ITEST_SIGKILL_SLEEP:-30}"
}

# write_defer_reason <type> <id> <reason>
# Writes a single-line reason marker for callers (StorageMountService / TaskService)
# to disambiguate the legitimate exit-2 paths. Best-effort — failure to write does
# not abort the bake. Path: /tmp/unraid-aicliagents/.bake_defer_reason_${type}_${id}
# PHP side is responsible for unlink-after-read so stale reasons don't bleed across runs.
# Validates <reason> against AICLI_DEFER_REASONS — an unlisted reason still writes
# (non-breaking) but emits a stderr warning so a new caller can't silently invent a
# reason PHP won't recognise.
write_defer_reason() {
    local type="${1:-}"
    local id="${2:-}"
    local reason="${3:-unknown}"
    [ -n "$type" ] && [ -n "$id" ] || return 0
    case " $AICLI_DEFER_REASONS " in
        *" $reason "*) : ;;
        *) echo "[write_defer_reason] WARN: unlisted defer reason '$reason' (not in AICLI_DEFER_REASONS — add it + a TaskService.php message)" >&2 ;;
    esac
    local sanitised_id="${id//[^a-zA-Z0-9_-]/_}"
    local marker="/tmp/unraid-aicliagents/.bake_defer_reason_${type}_${sanitised_id}"
    # Truncate to a single short line; reason should be a stable identifier.
    printf '%s\n' "$reason" > "$marker" 2>/dev/null || true
}

# _op_defer <type> <id> <component> <event> <reason> [payload_json]   (#1312)
# THE shared guarded-mutation DEFER tail, run by EVERY exit-2 defer site in op_bake
# AND op_consolidate (the two critical mutation hot paths): emit the op's "deferred"
# lifecycle event, write the defer-reason marker, then `exit 2` — the code the
# supervisor reads as "deferred, retry when idle" (NOT a failure, so the failure
# counter is untouched). The mutation CORES legitimately differ (a delta write vs a
# mksquashfs merge) and stay separate; this centralises only the part that MUST stay
# identical — the defer CONTRACT: the reason marker is always written BEFORE the exit,
# and the exit code is always 2 (never 1), so the two paths can never drift apart.
# Callers keep their op-specific side-effects (busy-cooldown stamp, update_task_status,
# a human log line) inline around the call. Omitting [payload_json] emits a default
# {type,id,reason} object; [level] defaults to "info". Ends in `exit 2`, so the
# subshell/op terminates here.
_op_defer() {
    local _type="$1" _id="$2" _component="$3" _event="$4" _reason="$5" _payload="${6:-}" _level="${7:-info}"
    [ -n "$_payload" ] || _payload="{\"type\":\"$_type\",\"id\":\"$_id\",\"reason\":\"$_reason\"}"
    lifecycle_log "$_level" "$_component" "$_event" "$_payload" 2>/dev/null || true
    write_defer_reason "$_type" "$_id" "$_reason"
    exit 2
}
