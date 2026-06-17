#!/bin/bash
# detect_backend.sh — Epic #1310 Step 2 / ADR 0001: the GENUINE device test that
# selects the storage backend. Replaces the fstype proxy (vfat→zram in
# _entity_paths + its PHP replica in StorageMountService::resolveHomeUpperPath).
#
#   flash       = a removable / USB-transport device (the wear-limited boot stick)
#                 → the full LAYERING ENGINE (ZRAM upper + SquashFS lowers + bake/
#                   consolidate/reclaim + manifest). Exists ONLY to spare Flash wear.
#   passthrough = any fixed durable device (HDD/SSD/NVMe, or a zfs/btrfs pool whose
#                 members are all fixed) → a plain directory, written directly.
#
# Data-safety invariant: ERR TOWARD FLASH whenever detection is uncertain — mis-
# treating a real stick as passthrough wears it out (bad); mis-treating a fixed
# disk as flash only wastes a little RAM/complexity (harmless).
#
# Pure decision (unit-tested): classify_backend_from_facts <removable> <transport>.
# Device-fact gathering: backend_for <path> / is_flash_device <path>.
#
# STEP 2 is non-behavioural: it only ADDS detection + status capabilities. The
# engine keeps layering regardless until Step 6 wires the passthrough backend +
# the storagectl short-circuit guard.

# classify_backend_from_facts <removable> <transport> -> "flash" | "passthrough"
#   removable: contents of /sys/block/<dev>/removable ("1" = removable)
#   transport: lsblk TRAN column ("usb","sata","nvme","" …)
# flash IFF removable==1 OR transport==usb; uncertain (both empty) → flash.
classify_backend_from_facts() {
    local removable="${1:-}" transport="${2:-}"
    # Uncertain: no facts at all → err toward flash (safe).
    if [ -z "$removable" ] && [ -z "$transport" ]; then
        printf 'flash'; return 0
    fi
    if [ "$removable" = "1" ] || [ "$transport" = "usb" ]; then
        printf 'flash'; return 0
    fi
    printf 'passthrough'
}

# _db_zpool_leaf_devs <pool> -> leaf vdev device basenames, one per line.
# zfs SOURCE is "pool/dataset", not a /dev node, so resolve the pool's backing
# devices from `zpool status`. Header/state/topology lines are skipped; what
# remains are the leaf device names (e.g. sdc3, nvme0n1p1).
_db_zpool_leaf_devs() {
    local pool="$1"
    command -v zpool >/dev/null 2>&1 || return 0
    zpool status "$pool" 2>/dev/null | awk -v pool="$pool" '
        /NAME[[:space:]]+STATE/ { incfg=1; next }
        !incfg { next }
        /^[[:space:]]*$/ { incfg=0; next }
        {
            name=$1
            if (name=="" || name==pool) next
            # Skip topology containers — only leaf devices map to real hardware.
            if (name ~ /^(mirror|raidz[0-9]*|spare|log|cache|special|dedup|replacing)/) next
            print name
        }'
}

# _db_parse_btrfs_devs — read `btrfs filesystem show` text on stdin, echo each member
# device path (one per line). Pure (no btrfs binary) so the multi-device enumeration
# is unit-testable. btrfs always prints full `path /dev/...` lines for present members.
_db_parse_btrfs_devs() {
    grep -oE 'path[[:space:]]+/dev/[^[:space:]]+' | awk '{print $NF}'
}

# _db_btrfs_member_devs <src> -> member device paths of the btrfs filesystem backing
# <src>, one per line. F10 (WP#1330): Unraid cache pools are classically MULTI-DEVICE
# btrfs, but only zfs pools were enumerated — a removable btrfs member was never seen.
_db_btrfs_member_devs() {
    local src="$1"
    command -v btrfs >/dev/null 2>&1 || return 0
    btrfs filesystem show "$src" 2>/dev/null | _db_parse_btrfs_devs
}

# _db_dev_facts <dev-or-basename> -> echoes "<removable> <transport>".
# Resolves a partition to its whole disk (lsblk PKNAME) before reading facts.
_db_dev_facts() {
    local dev="$1" base removable transport
    # Strip a leading /dev/ and resolve partition → whole disk.
    base="$(lsblk -no PKNAME "/dev/${dev#/dev/}" 2>/dev/null | awk 'NF{print;exit}')"
    [ -n "$base" ] || base="${dev#/dev/}"
    removable="$(cat "/sys/block/$base/removable" 2>/dev/null)"
    transport="$(lsblk -dno TRAN "/dev/$base" 2>/dev/null | awk 'NR==1{print}')"
    printf '%s %s' "$removable" "$transport"
}

# backend_for <path> -> "flash" | "passthrough". Resolves the path's backing
# block device(s) and classifies. Any unresolvable step errs toward flash. For a
# multi-device pool, ANY removable/USB member → flash (wear concern dominates).
backend_for() {
    local path="$1" src fst
    # F9 (WP#1332): ONE findmnt for SOURCE+FSTYPE instead of two (halves the spawns on
    # the device-probe path). SOURCE is whitespace-free for block devices and zfs
    # pool/dataset names, so a plain field split is safe.
    read -r src fst < <(findmnt -no SOURCE,FSTYPE --target "$path" 2>/dev/null)
    [ -n "$src" ] || { printf 'flash'; return 0; }     # can't resolve → flash (safe)

    local devs=() d facts removable transport verdict
    if [ "$fst" = "zfs" ]; then
        local pool="${src%%/*}"
        mapfile -t devs < <(_db_zpool_leaf_devs "$pool")
    elif [ "$fst" = "btrfs" ]; then
        # F10 (WP#1330): btrfs may be MULTI-DEVICE (Unraid cache pools are classically
        # multi-device btrfs). Enumerate ALL members so the "ANY removable/USB member →
        # flash" rule applies, instead of classifying only the single findmnt SOURCE.
        # Empty (btrfs tool missing / degraded) falls through to the err-toward-flash
        # guard below — a multi-device fs we can't resolve is uncertain → flash.
        mapfile -t devs < <(_db_btrfs_member_devs "$src")
    else
        devs=("$src")
    fi
    [ "${#devs[@]}" -gt 0 ] || { printf 'flash'; return 0; }   # no devs → flash (safe)

    for d in "${devs[@]}"; do
        facts="$(_db_dev_facts "$d")"
        removable="${facts%% *}"
        transport="${facts##* }"
        verdict="$(classify_backend_from_facts "$removable" "$transport")"
        if [ "$verdict" = "flash" ]; then
            printf 'flash'; return 0      # any flash-class member → flash
        fi
    done
    printf 'passthrough'
}

# is_flash_device <path> -> 0 (true) if the backend is flash.
is_flash_device() {
    [ "$(backend_for "$1")" = "flash" ]
}

# entity_upper_mode <persist> -> "zram" | "disk". WHERE a FLASH-backend (layering)
# entity's OverlayFS upper lives: a removable/USB device (flash-wear concern) buffers
# the upper in ZRAM (RAM); a durable device writes the upper straight to disk. #1322:
# this is the GENUINE device test (backend_for: removable/USB → flash), REPLACING the
# old vfat-fstype proxy in common.sh _entity_paths + its PHP replica. Err toward zram
# (the wear-safe choice) on an unresolvable device, mirroring backend_for's flash bias.
#
# AICLI_ITEST_UPPER_MODE forces the verdict for the L3.5 harness: loopback images all
# classify uncertain→flash under the genuine test, so the suite needs to set the
# wear-driven upper-mode per-case INDEPENDENTLY of the AICLI_ITEST_BACKEND layering
# verdict (a disk-upper ext4 case still forces the layering engine via flash).
entity_upper_mode() {
    local _forced="${AICLI_ITEST_UPPER_MODE:-}"
    if [ "$_forced" = "zram" ] || [ "$_forced" = "disk" ]; then
        printf '%s' "$_forced"; return 0
    fi
    if [ "$(backend_for "${1:-/}")" = "flash" ]; then printf 'zram'; else printf 'disk'; fi
}

# ---------------------------------------------------------------------------
# Step 6: the EFFECTIVE backend for an ENTITY = the device verdict tempered by
# the entity's current state. THE data-safety invariant: an entity that already
# has .sqsh layers is on the flash (layering) backend and MUST keep using it
# regardless of the device test — its data lives in those layers, so a plain-dir
# passthrough backend would strand them. Only a layer-free entity adopts the
# device's backend (a fresh entity on a durable device starts passthrough;
# existing layered data is migrated EXPLICITLY, never auto-stranded). This is
# what lets the passthrough guard ship to a passthrough-classified box (.4)
# without losing its existing layered homes.

# effective_backend_from_facts <device_backend> <has_layers> -> flash|passthrough (pure).
effective_backend_from_facts() {
    local device_backend="${1:-flash}" has_layers="${2:-0}"
    if [ "$has_layers" = "1" ]; then printf 'flash'; return 0; fi
    [ "$device_backend" = "passthrough" ] && { printf 'passthrough'; return 0; }
    printf 'flash'
}

# _entity_has_layers <persist> <type> <id> -> "1" if any .sqsh layer exists, else "0".
_entity_has_layers() {
    local persist="$1" type="$2" id="$3" f
    shopt -s nullglob
    for f in "$persist"/"${type}_${id}_"*.sqsh; do
        if [ -e "$f" ]; then shopt -u nullglob; printf '1'; return 0; fi
    done
    shopt -u nullglob
    printf '0'
}

# _manifest_raw_locked <mpath> — print the manifest contents via the SHARED-locked
# read when available (S-04), plain read otherwise. Shared by the two manifest
# probes below so they can never diverge on read discipline.
_manifest_raw_locked() {
    if declare -f manifest_read_locked >/dev/null 2>&1; then
        manifest_read_locked "$1" 2>/dev/null
    else
        cat "$1" 2>/dev/null
    fi
}

# _entity_manifest_segment_from_raw <raw_json> <type> <id> — print the manifest
# segment from just AFTER this entity's key up to the NEXT entity key (or end).
# Pure parameter expansion. Entity keys are the ONLY places `"home/` / `"agent/`
# can appear (layer filenames use `home_`/`agent_`; persistence paths are
# absolute, so a quote is always followed by `/`, never `home/`). S-10 (#1354).
_entity_manifest_segment_from_raw() {
    local raw="$1" type="$2" id="$3" seg h a
    case "$raw" in *"\"${type}/${id}\""*) : ;; *) return 0 ;; esac
    seg="${raw#*\"${type}/${id}\"}"
    h="${seg%%\"home/*}"; a="${seg%%\"agent/*}"
    if [ "${#h}" -le "${#a}" ]; then seg="$h"; else seg="$a"; fi
    printf '%s' "$seg"
}

# _entity_manifest_backend <type> <id> -> "flash" | "passthrough" | "" (no field /
# entity absent / manifest absent). S-10 (#1354): reads the ADDITIVE per-entity
# "backend" key written by manifest_set_backend (manifest_write.sh) after a
# graduate migration. Pure-bash; AICLI_MANIFEST_PATH overrides for unit tests.
_entity_manifest_backend() {
    local type="$1" id="$2"
    local mpath="${AICLI_MANIFEST_PATH:-/boot/config/plugins/unraid-aicliagents/layer_manifest.json}"
    [ -f "$mpath" ] || { printf ''; return 0; }
    local seg
    seg="$(_entity_manifest_segment_from_raw "$(_manifest_raw_locked "$mpath")" "$type" "$id")"
    [ -n "$seg" ] || { printf ''; return 0; }
    case "$(printf '%s' "$seg" | grep -oE '"backend"[[:space:]]*:[[:space:]]*"(flash|passthrough)"' 2>/dev/null | head -1)" in
        *passthrough*) printf 'passthrough' ;;
        *flash*)       printf 'flash' ;;
        *)             printf '' ;;
    esac
}

# _entity_manifest_expects_layers <type> <id> -> "1" if the layer manifest records
# this entity, else "0". F2 (WP#1326): the manifest records an entity ONLY once it
# has baked .sqsh layers — a fresh passthrough entity is never present. So a recorded
# entity is a managed FLASH entity, even if its on-disk .sqsh files have VANISHED.
# Pure-bash, no php/jq (the bash units run runtime-free). The key is matched as
# "type/id": (quote-delimited + colon) so home/it2 never matches home/it20.
# AICLI_MANIFEST_PATH overrides the path for the unit test.
# S-10 (#1354): a recorded entity whose manifest "backend" is PASSTHROUGH expects
# NO layers — graduation moved its layers to .graduated/ and flipped the field in
# one locked write, so the F2 vanished-layers guard must not re-pin it to flash.
# Entities WITHOUT the field behave exactly as before (recorded ⇒ expects layers).
_entity_manifest_expects_layers() {
    local type="$1" id="$2"
    local mpath="${AICLI_MANIFEST_PATH:-/boot/config/plugins/unraid-aicliagents/layer_manifest.json}"
    [ -f "$mpath" ] || { printf '0'; return 0; }
    # S-04 (#1352): prefer the SHARED-locked read (manifest_read_locked in
    # manifest_write.sh, when the caller sourced it — storagectl does) so the
    # check can never see a torn mid-write manifest. This lib stays dependency-
    # free: fall back to the plain direct read when the helper (or flock, e.g.
    # early boot) is unavailable.
    local _meml_raw
    _meml_raw="$(_manifest_raw_locked "$mpath")"
    if printf '%s' "$_meml_raw" | grep -q "\"${type}/${id}\"[[:space:]]*:" 2>/dev/null; then
        # Recorded — but a graduated (backend=passthrough) entity expects none.
        local _meml_seg
        _meml_seg="$(_entity_manifest_segment_from_raw "$_meml_raw" "$type" "$id")"
        case "$(printf '%s' "$_meml_seg" | grep -oE '"backend"[[:space:]]*:[[:space:]]*"passthrough"' 2>/dev/null)" in
            *passthrough*) printf '0' ;;
            *)             printf '1' ;;
        esac
    else
        printf '0'
    fi
}

# effective_backend <type> <id> <persist> -> flash|passthrough.
# Test hook: AICLI_ITEST_BACKEND forces the DEVICE verdict (harness only, honoured
# regardless of the real device so the L3.5 passthrough case can run on any box).
effective_backend() {
    local type="$1" id="$2" persist="$3" dev hl
    # F9 (WP#1332): a layered entity is ALWAYS flash (the data lives in the layers) —
    # do the ZERO-subprocess on-disk glob FIRST and SHORT-CIRCUIT, so the expensive
    # device probe (findmnt/lsblk[/zpool] ≈ 7-9 spawns) is SKIPPED for every layered
    # production entity (the common case on the 5s UI poll). Only a layer-free entity
    # reaches the device test.
    hl="$(_entity_has_layers "$persist" "$type" "$id")"
    if [ "$hl" = "1" ]; then printf 'flash'; return 0; fi

    dev="${AICLI_ITEST_BACKEND:-$(backend_for "$persist")}"
    # F2 (WP#1326): the on-disk glob reads 0 layers for a FLASH entity whose .sqsh
    # files have VANISHED, while the manifest still records it. Adopting passthrough
    # there binds an EMPTY plain dir over the loss (no manifest read, no classifier,
    # exit 0). Keep a manifest-recorded entity on flash so op_mount's classifier
    # adjudicates the loss (strict-halt) instead of a silent empty bind. Checked ONLY
    # on the passthrough-candidate path, so fresh passthrough entities stay cheap.
    if [ "$dev" = "passthrough" ] \
        && [ "$(_entity_manifest_expects_layers "$type" "$id")" = "1" ]; then
        printf 'flash'; return 0
    fi
    effective_backend_from_facts "$dev" "$hl"
}

# ---------------------------------------------------------------------------
# S-01 (#1351) — Backend detection v2: capability probe + policy matrix.
# DARK PHASE: everything below is ADDITIVE. The functions above keep making every
# live decision (backend_for / entity_upper_mode / effective_backend are
# byte-identical); nothing routes through classify_target_from_facts or
# probe_target yet. The policy switch-over (e.g. internal-boot → passthrough)
# is a LATER phase. All code below is function definitions only — sourcing this
# file stays as cheap as before; the heavy probe work runs only when
# probe_target / the --json CLI / `storagectl probe` is explicitly invoked.
#
# Three new classification axes (replacing the binary removable/transport axis
# once the switch-over lands):
#   durability  durable | volatile | network     (fstype mapping)
#   wear        wear_sensitive | wear_normal     (removable==1 OR transport==usb)
#   posix       posix_full | posix_none          (symlinks/xattrs capability)
# Spec: docs/specs/STORAGE_BACKEND_DETECTION_V2.md

# _ct_compute_axes <fstype> <removable> <transport> — ZERO-subprocess axis
# derivation. Sets globals (no command substitution, so callers stay fork-free):
#   _CT_POSIX      posix_full | posix_none
#   _CT_DUR        durable | volatile | network
#   _CT_WEAR       wear_sensitive | wear_normal
#   _CT_UNCERTAIN  1 when a durable block target has NO device facts at all
#                  (wear then errs to wear_sensitive — the err-toward-flash bias).
# Volatile/network targets have no flash-wear axis → wear_normal, never uncertain.
_ct_compute_axes() {
    local fst="${1:-}" rm="${2:-}" tr="${3:-}"
    case "$fst" in
        ext4|xfs|btrfs|zfs|f2fs) _CT_POSIX="posix_full" ;;
        *)                       _CT_POSIX="posix_none" ;;   # vfat/exfat/ntfs/fuseblk/unknown
    esac
    case "$fst" in
        tmpfs|ramfs|overlay|zram|devtmpfs|squashfs) _CT_DUR="volatile" ;;
        nfs|nfs4|cifs|smb3|fuse.sshfs|sshfs|9p)     _CT_DUR="network" ;;
        *)                                          _CT_DUR="durable" ;;
    esac
    _CT_UNCERTAIN=0
    if [ "$rm" = "1" ] || [ "$tr" = "usb" ]; then
        _CT_WEAR="wear_sensitive"
    elif [ "$_CT_DUR" != "durable" ]; then
        _CT_WEAR="wear_normal"
    elif [ -z "$rm" ] && [ -z "$tr" ]; then
        _CT_WEAR="wear_sensitive"; _CT_UNCERTAIN=1    # uncertain → err toward flash
    else
        _CT_WEAR="wear_normal"
    fi
}

# classify_target_from_facts <fstype> <removable> <transport> <rota> <mount_class>
#   -> "engine=<layering|passthrough>;upper=<zram|disk>;refuse=<0|1>;warn=<csv>"
# The PURE v2 policy matrix (zero subprocesses — same testable style as
# classify_backend_from_facts). DARK: nothing consumes this verdict yet.
#   engine  passthrough IFF posix_full AND durable AND wear_normal AND not refused;
#           anything else (incl. uncertain facts) → layering (err toward flash).
#   upper   zram IFF wear_sensitive OR posix_none; else disk.
#   refuse  1 IFF volatile (tmpfs/ramfs/overlay/zram) or mount_class tmpfs.
#   warn    csv of: volatile_target, network_target (per-kind home-refusal applied
#           by the caller at switch-over — see spec), facts_uncertain, posix_none,
#           via_user_share (FUSE overhead), array_rotational (HDD bake writes).
classify_target_from_facts() {
    local fst="${1:-}" rm="${2:-}" tr="${3:-}" rota="${4:-}" mclass="${5:-}"
    _ct_compute_axes "$fst" "$rm" "$tr"
    local refuse=0 warn="" engine upper
    if [ "$_CT_DUR" = "volatile" ] || [ "$mclass" = "tmpfs" ]; then
        refuse=1; warn="volatile_target"
    fi
    [ "$_CT_DUR" = "network" ]      && warn="${warn:+$warn,}network_target"
    [ "$_CT_UNCERTAIN" = "1" ]      && warn="${warn:+$warn,}facts_uncertain"
    [ "$_CT_POSIX" = "posix_none" ] && warn="${warn:+$warn,}posix_none"
    [ "$mclass" = "user_share" ]    && warn="${warn:+$warn,}via_user_share"
    if [ "$mclass" = "array" ] && [ "$rota" = "1" ]; then
        warn="${warn:+$warn,}array_rotational"
    fi
    if [ "$refuse" -eq 0 ] && [ "$_CT_POSIX" = "posix_full" ] \
        && [ "$_CT_DUR" = "durable" ] && [ "$_CT_WEAR" = "wear_normal" ]; then
        engine="passthrough"
    else
        engine="layering"
    fi
    if [ "$_CT_WEAR" = "wear_sensitive" ] || [ "$_CT_POSIX" = "posix_none" ]; then
        upper="zram"
    else
        upper="disk"
    fi
    printf 'engine=%s;upper=%s;refuse=%s;warn=%s' "$engine" "$upper" "$refuse" "$warn"
}

# graduate_precondition_from_facts <probe_engine> <device_backend> <effective_backend> <has_layers>
#   -> prints "ok" (return 0) or the refusal reason (return 1). S-10 (#1354):
# the PURE precondition for `storagectl graduate` (zero subprocesses — the same
# testable style as classify_target_from_facts). Graduation is allowed ONLY when
# ALL of:
#   probe_engine      == passthrough  (v2 capability probe approves the target —
#                                      posix_full + durable + wear_normal)
#   device_backend    == passthrough  (the LIVE v1 device verdict — what
#                                      effective_backend will consult AFTER the
#                                      un-pin; if v1 still says flash the entity
#                                      would re-adopt the layering engine and the
#                                      migration would strand it)
#   effective_backend == flash        (the entity is currently ON the layering
#                                      engine; a passthrough entity has nothing
#                                      to graduate)
#   has_layers        == 1            (there is layered data to migrate; a
#                                      layer-free flash entity just adopts
#                                      passthrough on its own)
# Conservative by construction: ANY missing/odd fact refuses.
graduate_precondition_from_facts() {
    local engine="${1:-}" device="${2:-}" effective="${3:-}" has_layers="${4:-0}"
    if [ "$engine" != "passthrough" ]; then
        printf 'engine_not_passthrough'; return 1
    fi
    if [ "$device" != "passthrough" ]; then
        printf 'device_not_passthrough'; return 1
    fi
    if [ "$effective" != "flash" ]; then
        printf 'not_flash_entity'; return 1
    fi
    if [ "$has_layers" != "1" ]; then
        printf 'no_layers'; return 1
    fi
    printf 'ok'; return 0
}

# graduate_offer_from_facts <device_backend> <probe_wear> <has_layers> <has_qualifying_target>
#   -> prints "ok" (return 0) or the refusal reason (return 1). Bug #1380:
# the USER-INTENT graduate gate — distinct from graduate_precondition_from_facts
# (which gates the in-place layering→passthrough op_graduate). "Graduate" here
# means MOVE THE DATA OFF A USB FLASH DRIVE onto a durable non-array non-flash
# target. Offer it ONLY when ALL of:
#   device_backend        == flash           (the v1 GENUINE device test classes
#                                             the persist device flash — i.e. a
#                                             removable/USB transport device)
#   probe_wear            == wear_sensitive  (the v2 probe's wear axis confirms a
#                                             genuine USB/removable device; an
#                                             internal-boot ZFS/SSD is wear_normal
#                                             and MUST yield false even though it
#                                             may carry .sqsh layers)
#   has_layers            == 1               (there is layered data on the stick
#                                             to move off it)
#   has_qualifying_target == 1               (criterion b: at least one durable,
#                                             non-array, non-flash, passthrough-
#                                             capable, non-refused target exists
#                                             to move the data TO)
# Conservative by construction: ANY missing/odd fact refuses. Pure (zero
# subprocesses — the classify_target_from_facts testable style); the PHP mirror
# is FileStorage::canGraduate.
graduate_offer_from_facts() {
    local device="${1:-}" wear="${2:-}" has_layers="${3:-0}" has_target="${4:-0}"
    if [ "$device" != "flash" ]; then
        printf 'device_not_flash'; return 1
    fi
    if [ "$wear" != "wear_sensitive" ]; then
        printf 'not_wear_sensitive'; return 1
    fi
    if [ "$has_layers" != "1" ]; then
        printf 'no_layers'; return 1
    fi
    if [ "$has_target" != "1" ]; then
        printf 'no_qualifying_target'; return 1
    fi
    printf 'ok'; return 0
}

# mount_class_for <path> -> boot_usb | boot_internal | array | pool:<name> |
#   ud_disk | ud_addons | remote | user_share | tmpfs | other
# realpath-resolves FIRST (exclusive-share symlinks: /mnt/user/<share> →
# /mnt/<pool>/<share>) and classifies the REALPATH; sets _MC_VIA_USER_SHARE=1
# when an original /mnt/user path diverged. This is the superset that will
# absorb classify-path.sh (S-07) — classify-path.sh consumers are NOT re-pointed
# in this (dark) phase.
# Test hooks (AICLI_ITEST_* precedent):
#   AICLI_ITEST_MOUNT_CLASS      force the whole verdict
#   AICLI_FAKE_BOOT_FSTYPE       /boot fstype override (R-11)
#   AICLI_FAKE_BOOT_REMOVABLE / AICLI_FAKE_BOOT_TRANSPORT  /boot device facts
#   AICLI_ITEST_PERSIST_FSTYPE   target fstype override (tmpfs arm; mirrors common.sh)
#   AICLI_DISKS_INI              disks.ini path override (pool-name lookup)
mount_class_for() {
    # Emitting wrapper. Callers that need the via-user-share marker MUST use
    # _mc_classify directly in the same shell and read the globals — a $()
    # capture of THIS wrapper forks a subshell and loses _MC_VIA_USER_SHARE
    # (bug found by L3.5 case BM08, fixed 2026-06).
    _mc_classify "${1:-}"
    printf '%s' "$_MC_CLASS"
}

# _mc_classify <path> — same-shell core: sets _MC_CLASS + _MC_VIA_USER_SHARE,
# writes nothing to stdout.
_mc_classify() {
    _MC_CLASS=""
    _MC_VIA_USER_SHARE=0
    if [ -n "${AICLI_ITEST_MOUNT_CLASS:-}" ]; then
        _MC_CLASS="$AICLI_ITEST_MOUNT_CLASS"; return 0
    fi
    local path="${1:-}" rp
    rp="$(realpath -m "$path" 2>/dev/null)"; [ -n "$rp" ] || rp="$path"
    case "$path" in
        /mnt/user/*) case "$rp" in /mnt/user/*) : ;; *) _MC_VIA_USER_SHARE=1 ;; esac ;;
    esac

    # /boot: fstype zfs OR a non-removable backing device → boot_internal
    # (Unraid 7.3 internal boot); otherwise boot_usb. Uncertain facts classify
    # flash via classify_backend_from_facts → boot_usb (err toward flash).
    case "$rp" in
        /boot|/boot/*)
            local bfst brm btr bsrc bfacts
            bfst="${AICLI_FAKE_BOOT_FSTYPE:-$(findmnt -no FSTYPE --target /boot 2>/dev/null)}"
            if [ "$bfst" = "zfs" ]; then _MC_CLASS='boot_internal'; return 0; fi
            if [ -n "${AICLI_FAKE_BOOT_REMOVABLE:-}" ] || [ -n "${AICLI_FAKE_BOOT_TRANSPORT:-}" ]; then
                brm="${AICLI_FAKE_BOOT_REMOVABLE:-}"; btr="${AICLI_FAKE_BOOT_TRANSPORT:-}"
            else
                bsrc="$(findmnt -no SOURCE --target /boot 2>/dev/null)"
                brm=""; btr=""
                if [ -n "$bsrc" ]; then
                    bfacts="$(_db_dev_facts "$bsrc")"
                    brm="${bfacts%% *}"; btr="${bfacts##* }"
                fi
            fi
            if [ "$(classify_backend_from_facts "$brm" "$btr")" = "flash" ]; then
                _MC_CLASS='boot_usb'
            else
                _MC_CLASS='boot_internal'
            fi
            return 0 ;;
    esac

    case "$rp" in
        /mnt/?*)
            local mnt_name="${rp#/mnt/}"; mnt_name="${mnt_name%%/*}"
            case "$mnt_name" in
                user|user0) _MC_CLASS='user_share'; return 0 ;;
                disks)      _MC_CLASS='ud_disk';    return 0 ;;
                addons)     _MC_CLASS='ud_addons';  return 0 ;;
                remotes)    _MC_CLASS='remote';     return 0 ;;
            esac
            if [[ "$mnt_name" =~ ^disk[0-9]+$ ]]; then _MC_CLASS='array'; return 0; fi
            # Pool names from disks.ini (ports the classify-path.sh awk — S-07 path
            # to retiring it; sections are ["cache"] etc., type="Cache" = a pool).
            local dini="${AICLI_DISKS_INI:-/var/local/emhttp/disks.ini}" pool
            if [ -f "$dini" ]; then
                while IFS= read -r pool; do
                    [ -n "$pool" ] || continue
                    if [ "$mnt_name" = "$pool" ]; then
                        _MC_CLASS="pool:$pool"; return 0
                    fi
                done < <(awk -F'=' '
                    /^\[/ { section=$0; gsub(/[\[\]"]/, "", section) }
                    /^type=/ && $2 == "\"Cache\"" {
                        name=section; gsub(/[0-9]+$/, "", name); print name
                    }' "$dini" | sort -u)
            fi
            ;;
    esac

    # tmpfs by REAL fstype (never a path prefix), then other.
    local fst
    fst="${AICLI_ITEST_PERSIST_FSTYPE:-$(findmnt -no FSTYPE --target "$rp" 2>/dev/null)}"
    case "$fst" in
        tmpfs|ramfs) _MC_CLASS='tmpfs'; return 0 ;;
    esac
    _MC_CLASS='other'
}

# _pt_json_str <s> — JSON-escape a string value (no surrounding quotes).
_pt_json_str() {
    local s="$1"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"; s="${s//$'\t'/\\t}"; s="${s//$'\r'/}"
    printf '%s' "$s"
}

# _pt_csv_to_json_array <csv> — "a,b" → ["a","b"]; "" → [].
_pt_csv_to_json_array() {
    local csv="$1" out="[" first=1 item
    local IFS=','
    for item in $csv; do
        [ -n "$item" ] || continue
        [ $first -eq 1 ] && first=0 || out="$out,"
        out="$out\"$(_pt_json_str "$item")\""
    done
    printf '%s]' "$out"
}

# probe_target <path> — the full capability probe. Emits single-line JSON
# (schema 1; see docs/specs/STORAGE_BACKEND_DETECTION_V2.md):
#   {schema, path, realpath, fstype, mount_class, via_user_share, durability,
#    wear, posix, rotational, max_file_bytes, engine, upper_mode, refuse,
#    warnings:[], reasons:[]}
# Reuses the existing fact-gatherers (_db_dev_facts, _db_zpool_leaf_devs,
# _db_btrfs_member_devs); member facts aggregate worst-case (ANY removable/USB
# member → wear concern dominates, mirroring backend_for).
# Test hook: AICLI_ITEST_PROBE_FACTS="<removable> <transport> <rota>" forces the
# aggregated device facts (loopback/CI targets have no genuine block facts).
probe_target() {
    local path="${1:-/}" rp fst mclass via_us
    rp="$(realpath -m "$path" 2>/dev/null)"; [ -n "$rp" ] || rp="$path"
    fst="${AICLI_ITEST_PERSIST_FSTYPE:-$(findmnt -no FSTYPE --target "$path" 2>/dev/null)}"
    # Same-shell classify (NOT a $() capture — that forks a subshell and loses
    # the _MC_VIA_USER_SHARE marker; L3.5 case BM08 pins this mechanism).
    _mc_classify "$path"
    mclass="$_MC_CLASS"
    via_us="false"; [ "${_MC_VIA_USER_SHARE:-0}" = "1" ] && via_us="true"

    # ---- device facts, aggregated over all backing members --------------------
    local agg_rm="" agg_tr="" agg_rota=""
    if [ -n "${AICLI_ITEST_PROBE_FACTS:-}" ]; then
        read -r agg_rm agg_tr agg_rota <<< "$AICLI_ITEST_PROBE_FACTS"
        [ "$agg_rm" = "-" ] && agg_rm=""
        [ "$agg_tr" = "-" ] && agg_tr=""
        [ "$agg_rota" = "-" ] && agg_rota=""
    else
        local src devs=() d facts rm tr rota base
        src="$(findmnt -no SOURCE --target "$path" 2>/dev/null)"
        if [ -n "$src" ]; then
            if [ "$fst" = "zfs" ]; then
                mapfile -t devs < <(_db_zpool_leaf_devs "${src%%/*}")
            elif [ "$fst" = "btrfs" ]; then
                mapfile -t devs < <(_db_btrfs_member_devs "$src")
            else
                devs=("$src")
            fi
        fi
        # ${devs[@]+…}: empty-array-safe under `set -u` (storagectl runs with -u).
        for d in ${devs[@]+"${devs[@]}"}; do
            [ -n "$d" ] || continue
            facts="$(_db_dev_facts "$d")"
            rm="${facts%% *}"; tr="${facts##* }"
            if [ "$rm" = "1" ]; then agg_rm="1"; elif [ -n "$rm" ] && [ -z "$agg_rm" ]; then agg_rm="$rm"; fi
            if [ "$tr" = "usb" ]; then agg_tr="usb"; elif [ -n "$tr" ] && [ -z "$agg_tr" ]; then agg_tr="$tr"; fi
            base="$(lsblk -no PKNAME "/dev/${d#/dev/}" 2>/dev/null | awk 'NF{print;exit}')"
            [ -n "$base" ] || base="${d#/dev/}"
            rota="$(lsblk -dno ROTA "/dev/$base" 2>/dev/null | awk 'NR==1{gsub(/[[:space:]]/,"");print}')"
            if [ "$rota" = "1" ]; then agg_rota="1"; elif [ -n "$rota" ] && [ -z "$agg_rota" ]; then agg_rota="$rota"; fi
        done
    fi

    # ---- policy verdict (pure) + axes -----------------------------------------
    local verdict engine upper refuse warn
    verdict="$(classify_target_from_facts "$fst" "$agg_rm" "$agg_tr" "$agg_rota" "$mclass")"
    engine="${verdict#engine=}";  engine="${engine%%;*}"
    upper="${verdict#*;upper=}";  upper="${upper%%;*}"
    refuse="${verdict#*;refuse=}"; refuse="${refuse%%;*}"
    warn="${verdict#*;warn=}"
    # Re-derive the axes for the JSON (same zero-fork helper classify used).
    _ct_compute_axes "$fst" "$agg_rm" "$agg_tr"

    # reasons: the policy rule(s) that decided the engine.
    local reasons=""
    if [ "$engine" = "passthrough" ]; then
        reasons="posix_full_durable_local"
    else
        [ "$_CT_DUR" = "volatile" ]          && reasons="${reasons:+$reasons,}volatile_target"
        [ "$_CT_DUR" = "network" ]           && reasons="${reasons:+$reasons,}network_target"
        [ "$_CT_POSIX" = "posix_none" ]      && reasons="${reasons:+$reasons,}posix_none"
        [ "$_CT_WEAR" = "wear_sensitive" ]   && reasons="${reasons:+$reasons,}wear_sensitive"
        [ "$_CT_UNCERTAIN" = "1" ]           && reasons="${reasons:+$reasons,}facts_uncertain"
    fi

    local refuse_b="false"; [ "$refuse" = "1" ] && refuse_b="true"
    local rota_b="false";   [ "$agg_rota" = "1" ] && rota_b="true"
    local max_file_bytes=0; [ "$fst" = "vfat" ] && max_file_bytes=4294967295

    printf '{"schema":1,"path":"%s","realpath":"%s","fstype":"%s","mount_class":"%s","via_user_share":%s,"durability":"%s","wear":"%s","posix":"%s","rotational":%s,"max_file_bytes":%s,"engine":"%s","upper_mode":"%s","refuse":%s,"warnings":%s,"reasons":%s}\n' \
        "$(_pt_json_str "$path")" "$(_pt_json_str "$rp")" "$(_pt_json_str "$fst")" \
        "$(_pt_json_str "$mclass")" "$via_us" "$_CT_DUR" "$_CT_WEAR" "$_CT_POSIX" \
        "$rota_b" "$max_file_bytes" "$engine" "$upper" "$refuse_b" \
        "$(_pt_csv_to_json_array "$warn")" "$(_pt_csv_to_json_array "$reasons")"
}

# CLI entry — when EXECUTED (not sourced): `detect_backend.sh <path>` prints the
# backend (flash|passthrough) on stdout, so PHP (FileStorage::backendForPath) can
# read it without re-implementing the device test. Sourcing (storagectl, the unit
# test) does NOT trigger this. S-01 (#1351): `--json <path>` prints the probe
# JSON instead; the bare <path> contract is byte-identical (the dark invariant).
if [ "${BASH_SOURCE[0]:-_x}" = "${0:-_y}" ]; then
    if [ "${1:-}" = "--json" ]; then
        probe_target "${2:-/}"
    else
        backend_for "${1:-/}"
        printf '\n'
    fi
fi
