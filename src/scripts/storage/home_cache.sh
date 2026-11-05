#!/bin/bash
source "$(dirname "${BASH_SOURCE[0]}")/resolve_paths.sh" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# AICliAgents: cache folders of a plain-folder home (BOOT_DISK_SPACE.md R5, #537, #533).
#
# A plain home is one bind mount of a folder on the boot disk. Without this file
# every cache (npm, pip, tool downloads) is written to that folder, and a cache
# of several GB fills a small boot disk. Here each cache folder of the home
# (home_cache_dirs in common.sh) becomes a bind mount of a folder in RAM (one
# tmpfs per home) or in a folder on a pool.
#
# Sourced by common.sh. Needs: home_cache_dirs, cmn_log (common.sh), and
# lifecycle_log (resolve_paths.sh, optional).
#
# Test seams (environment):
#   AICLI_HOME_CACHE_BASE   RAM base folder   (default ${MOUNT_ROOT}/cache)
#   AICLI_HC_LOCATION / AICLI_HC_PATH / AICLI_HC_RAM_MB   replace the settings
#   AICLI_PROC_SCAN_DIR     replaces /proc in the "is the home in use" scan

# ---- settings ----------------------------------------------------------------

home_cache_ram_base() { printf '%s\n' "${AICLI_HOME_CACHE_BASE:-${MOUNT_ROOT}/cache}"; }

# _hc_cfg <key> — the raw value of a plugin setting ("" when absent).
_hc_cfg() {
    local cfg="${CONFIG_FILE:-${PLUGIN_BASE}/unraid-aicliagents.cfg}"
    [ -f "$cfg" ] || return 0
    grep -oP "^$1=\"?\K[^\"]*(?=\"?\$)" "$cfg" 2>/dev/null | head -1
}

# home_cache_location_setting -> ram | pool
home_cache_location_setting() {
    local v="${AICLI_HC_LOCATION-}"
    [ -n "${AICLI_HC_LOCATION+x}" ] || v="$(_hc_cfg home_cache_location)"
    case "$v" in pool) printf 'pool\n' ;; *) printf 'ram\n' ;; esac
}

home_cache_path_setting() {
    if [ -n "${AICLI_HC_PATH+x}" ]; then printf '%s\n' "$AICLI_HC_PATH"; else _hc_cfg home_cache_path; fi
}

# home_cache_ram_mb [<raw>] -> an integer 256..8192 (default 1024). Pure when given a value.
home_cache_ram_mb() {
    local v
    if [ "$#" -gt 0 ]; then v="$1"
    elif [ -n "${AICLI_HC_RAM_MB+x}" ]; then v="$AICLI_HC_RAM_MB"
    else v="$(_hc_cfg home_cache_ram_mb)"; fi
    case "$v" in ''|*[!0-9]*) v=1024 ;; esac
    [ "$v" -lt 256 ] && v=256
    [ "$v" -gt 8192 ] && v=8192
    printf '%s\n' "$v"
}

# home_cache_pool_of <path> — the pool folder (/mnt/<name>) a path lies under.
# Prints nothing for a path that is not under a pool: the user shares (FUSE),
# unassigned and remote disks, and the array disks are no pool.
home_cache_pool_of() {
    local p="${1:-}" name
    case "$p" in /mnt/*) : ;; *) return 0 ;; esac
    case "$p" in *..*) return 0 ;; esac
    # R-123 (#662): a "." segment or an empty segment ("/mnt/./user", "/mnt//user") hides the
    # share name from the check below.
    case "$p" in */./*|*/.|*//*) return 0 ;; esac
    name="${p#/mnt/}"; name="${name%%/*}"
    case "$name" in
        ''|user|user0|disks|remotes|rootshare|addons|disk[0-9]*) return 0 ;;
    esac
    case "$name" in *[!A-Za-z0-9._-]*) return 0 ;; esac
    printf '/mnt/%s\n' "$name"
}

# home_cache_pool_mounted <path> — prints 1 when the pool under <path> is mounted, else 0.
home_cache_pool_mounted() {
    local pool; pool="$(home_cache_pool_of "${1:-}")"
    if [ -n "$pool" ] && mountpoint -q "$pool" 2>/dev/null; then printf '1\n'; else printf '0\n'; fi
}

# home_cache_effective_location <location> <pool_path> <pool_mounted 0|1> -> ram | pool
# Pure. Pool only when asked for, the folder is under a pool, and that pool is mounted.
home_cache_effective_location() {
    local loc="${1:-ram}" path="${2:-}" mounted="${3:-0}"
    if [ "$loc" = "pool" ] && [ "$mounted" = "1" ] && [ -n "$(home_cache_pool_of "$path")" ]; then
        printf 'pool\n'
    else
        printf 'ram\n'
    fi
}

# home_cache_root <location> <pool_path> <user> — the folder that holds the cache
# folders of one home.
home_cache_root() {
    if [ "${1:-ram}" = "pool" ]; then
        printf '%s/%s\n' "${2%/}" "$3"
    else
        printf '%s/%s\n' "$(home_cache_ram_base)" "$3"
    fi
}

# home_cache_plan <location> <pool_path> <pool_mounted 0|1> <user>
# Pure. One line for each cache folder: "<source folder><TAB><folder in the home>".
# The pool folder absent (or the pool not mounted) gives the RAM plan.
home_cache_plan() {
    local loc="${1:-ram}" path="${2:-}" mounted="${3:-0}" user="${4:-}" eff root rel
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 1 ;; esac
    eff="$(home_cache_effective_location "$loc" "$path" "$mounted")"
    root="$(home_cache_root "$eff" "$path" "$user")"
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        printf '%s/%s\t%s\n' "$root" "$rel" "$rel"
    done < <(home_cache_dirs)
}

# ---- helpers -----------------------------------------------------------------

_hc_state_file() { printf '%s/.state/%s\n' "$(home_cache_ram_base)" "$1"; }

# _hc_state_get <user> <key>
_hc_state_get() {
    local f; f="$(_hc_state_file "$1")"
    [ -f "$f" ] || return 0
    sed -n "s/^$2=//p" "$f" 2>/dev/null | head -1
}

_hc_owner_ok() { [ -n "${1:-}" ] && [ "$1" != "root" ] && id "$1" >/dev/null 2>&1; }

# _hc_chain_has_symlink <base> <rel> — 0 when base or a part of base/rel is a
# symlink. A cache folder is never made or bound through a symlink.
_hc_chain_has_symlink() {
    local cur="$1" part rest="$2"
    [ -L "$cur" ] && return 0
    local IFS=/
    for part in $rest; do
        [ -n "$part" ] || continue
        cur="$cur/$part"
        [ -L "$cur" ] && return 0
    done
    return 1
}

# _hc_mkdir_owned <base> <rel> <owner> — make every missing part of base/rel and
# give each part it made to <owner>. A part that exists is left as it is.
_hc_mkdir_owned() {
    local cur="$1" rest="$2" owner="$3" part
    local IFS=/
    for part in $rest; do
        [ -n "$part" ] || continue
        cur="$cur/$part"
        [ -L "$cur" ] && return 1
        if [ ! -d "$cur" ]; then
            mkdir "$cur" 2>/dev/null || return 1
            _hc_owner_ok "$owner" && chown "$owner" "$cur" 2>/dev/null
        fi
    done
    return 0
}

# _hc_holders <dir> — "pid:name" of each process with its working folder or an
# open file under <dir>, comma-separated (for the log).
_hc_holders() {
    local dir="${1%/}" scan="${AICLI_PROC_SCAN_DIR:-/proc}" d f out=""
    for d in "$scan"/[0-9]*; do
        [ -d "$d" ] || continue
        for f in "$d/cwd" "$d"/fd/*; do
            case "$(readlink "$f" 2>/dev/null)" in
                "$dir"|"$dir"/*) out="$out${out:+,}${d##*/}:$(cat "$d/comm" 2>/dev/null)"; break ;;
            esac
        done
    done
    printf '%s' "${out:-unknown}"
}

# _hc_umount <target> <lazy_ok 0|1> — a real umount (3 tries). With lazy_ok a
# target that stays busy is detached lazily: only stop-plugin.sh sets it (full
# plugin stop or shutdown, where the home itself is detached the same way).
_hc_umount() {
    local t="$1" lazy="${2:-0}" i
    mountpoint -q "$t" 2>/dev/null || return 0
    for i in 1 2 3; do
        umount "$t" 2>/dev/null && return 0
        [ "$i" -lt 3 ] && sleep "${AICLI_UMOUNT_RETRY_S:-1}"
    done
    mountpoint -q "$t" 2>/dev/null || return 0
    if [ "$lazy" = "1" ]; then
        cmn_log WARN home-cache "cache bind $t still busy; detaching it lazily so the pool can stop"
        umount -l "$t" 2>/dev/null && return 0
    fi
    return 1
}

# home_cache_home_busy <mnt> — 0 when a process uses the home: its HOME is the
# mount, or its working folder lies under it.
home_cache_home_busy() {
    local mnt="${1%/}" scan="${AICLI_PROC_SCAN_DIR:-/proc}" d cwd
    [ -n "$mnt" ] || return 1
    for d in "$scan"/[0-9]*; do
        [ -d "$d" ] || continue
        cwd="$(readlink "$d/cwd" 2>/dev/null || true)"
        case "$cwd" in "$mnt"|"$mnt"/*) return 0 ;; esac
        if [ -r "$d/environ" ] && tr '\0' '\n' < "$d/environ" 2>/dev/null | grep -qxF "HOME=$mnt"; then
            return 0
        fi
    done
    return 1
}

# _hc_du_bytes <path>... — allocated bytes of existing paths (0 when none).
_hc_du_bytes() {
    local out
    [ "$#" -gt 0 ] || { printf '0\n'; return 0; }
    # AICLI_HC_NO_MEASURE=1 (set by the web request "Clear caches", #552): no du at all.
    # The freed bytes then read 0 and the caller takes the size from the last background
    # measurement.
    [ -z "${AICLI_HC_NO_MEASURE:-}" ] || { printf '0\n'; return 0; }
    out="$(timeout 60 du -s -B1 -- "$@" 2>/dev/null | awk '{s+=$1} END {print s+0}')"
    printf '%s\n' "${out:-0}"
}

# ---- one-time clean-up of the old cache content on the boot disk -------------

# home_cache_boot_dirs <persist_home> — the cache folders that exist in the plain
# home on the boot disk: real folders only, never through a symlink.
home_cache_boot_dirs() {
    local home="${1%/}" rel
    [ -d "$home" ] && [ ! -L "$home" ] || return 0
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        _hc_chain_has_symlink "$home" "$rel" && continue
        [ -d "$home/$rel" ] && printf '%s/%s\n' "$home" "$rel"
    done < <(home_cache_dirs)
}

# home_cache_on_boot_bytes <persist_home> — cache content still on the boot disk.
home_cache_on_boot_bytes() {
    local list; list="$(home_cache_boot_dirs "$1")"
    [ -n "$list" ] || { printf '0\n'; return 0; }
    # shellcheck disable=SC2046
    _hc_du_bytes $(printf '%s\n' "$list" | tr '\n' ' ')
}

# home_cache_wipe_boot <persist_home> — remove the CONTENT of each listed cache
# folder in the plain home (the folders stay). Never leaves the listed folders,
# never crosses a mount (-xdev), never follows a symlink (find does not). Prints
# the freed bytes.
home_cache_wipe_boot() {
    local list dir before=0 after=0
    list="$(home_cache_boot_dirs "$1")"
    [ -n "$list" ] || { printf '0\n'; return 0; }
    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        before=$((before + $(_hc_du_bytes "$dir")))
        find "$dir" -xdev -mindepth 1 -delete 2>/dev/null || true
        after=$((after + $(_hc_du_bytes "$dir")))
    done <<< "$list"
    local freed=$((before - after)); [ "$freed" -lt 0 ] && freed=0
    printf '%s\n' "$freed"
}

# _hc_tray_note <label> <step> — one finished Activity tray entry. Best effort;
# skipped in a sandboxed test run (AICLI_JOBS_DIR set), without php or the manager.
_hc_tray_note() {
    [ -z "${AICLI_JOBS_DIR:-}" ] || return 0
    local inc="${AICLI_MANAGER_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php}"
    [ -f "$inc" ] && command -v php >/dev/null 2>&1 || return 0
    timeout 20 php -d display_errors=0 -r '
        require_once $argv[1];
        \AICliAgents\Services\EventActor::$override = ["type" => "system"];
        $op = "home_cache_" . preg_replace("/[^A-Za-z0-9_]/", "_", $argv[2]) . "_" . time();
        \AICliAgents\Services\ActivityService::register($op, "storage", $argv[2]);
        \AICliAgents\Services\ActivityService::finish($op, $argv[3]);' -- "$inc" "$1" "$2" >/dev/null 2>&1 || true
}

# _hc_human <bytes> — "340 MB" / "1.2 GB".
_hc_human() {
    awk -v b="${1:-0}" 'BEGIN { if (b >= 1073741824) printf "%.1f GB", b/1073741824; else printf "%d MB", int((b+1048575)/1048576) }'
}

# _hc_home_alive <persist> <id> — 0 while the plain folder of the home exists. The
# folder is made by the mount BEFORE the cache step runs, so a missing folder means
# the home was removed while the cache step ran (#914). Nothing here may make it
# again: not the cache folders, not a marker.
_hc_home_alive() {
    [ -d "$1/passthrough/homes/$2" ]
}

# home_cache_clean_once <id> <persist> <persist_home> <mnt>
# The first mount after the R5 change: remove the old cache content from the boot
# disk ONCE. Only while no process uses the home (else it waits for the next
# mount). A marker records that it ran.
home_cache_clean_once() {
    local id="$1" persist="$2" home="$3" mnt="$4" marker freed
    marker="$persist/passthrough/.home-cache-cleaned/$id"
    [ -e "$marker" ] && return 0
    _hc_home_alive "$persist" "$id" || return 0   # #914: removed meanwhile
    if home_cache_home_busy "$mnt"; then
        cmn_log INFO home-cache "old cache content of home $id stays for now: a workspace uses the home"
        return 0
    fi
    freed="$(home_cache_wipe_boot "$home")"
    _hc_home_alive "$persist" "$id" || return 0   # #914: removed during the wipe; write no marker
    mkdir -p "$(dirname "$marker")" 2>/dev/null
    printf '%s\n' "$freed" > "$marker" 2>/dev/null || true
    cmn_log INFO home-cache "removed old cache content of home $id from the boot disk: freed $freed bytes"
    lifecycle_log "info" "home_cache" "home_cache_cleaned" "{\"id\":\"$id\",\"freed_bytes\":$freed}" 2>/dev/null || true
    if [ "$freed" -gt 0 ]; then
        _hc_tray_note "Old caches of home $id removed" "Freed $(_hc_human "$freed") on the boot disk"
    fi
    return 0
}

# ---- mount -------------------------------------------------------------------

# _hc_ensure_tmpfs <dir> <mb> <owner>
_hc_ensure_tmpfs() {
    local dir="$1" mb="$2" owner="$3" opts="mode=700" uid gid
    mkdir -p "$dir" 2>/dev/null || return 1
    if _hc_owner_ok "$owner"; then
        uid="$(id -u "$owner")"; gid="$(id -g "$owner")"
        opts="$opts,uid=$uid,gid=$gid"
    fi
    if mountpoint -q "$dir" 2>/dev/null; then
        mount -o "remount,size=${mb}m" "$dir" 2>/dev/null || true
        return 0
    fi
    mount -t tmpfs -o "size=${mb}m,$opts" tmpfs "$dir" 2>/dev/null
}

# home_cache_apply <id> <owner> <mnt> <persist> <location> <pool_path>
# Bind each cache folder of the home to its source (RAM or pool). Idempotent: a
# bind that already shows its source is left alone, so a second call stacks nothing.
# A busy old bind stays (never a lazy detach: #512); its holders are logged.
# Returns 0 when the plan is applied (a folder that cannot be bound is logged and
# stays on the boot disk).
home_cache_apply() {
    local id="$1" owner="$2" mnt="${3%/}" persist="$4" want="$5" pool_path="$6"
    local home="$persist/passthrough/homes/$id" eff root mounted src rel tgt mb a b
    case "$id" in ''|*[!A-Za-z0-9._-]*) return 1 ;; esac
    [ -d "$mnt" ] || return 1
    _hc_home_alive "$persist" "$id" || { cmn_log INFO home-cache "home $id has no folder any more: no cache step"; return 0; }   # #914
    mounted="$(home_cache_pool_mounted "$pool_path")"
    eff="$(home_cache_effective_location "$want" "$pool_path" "$mounted")"
    if [ "$want" = "pool" ] && [ "$eff" = "ram" ]; then
        cmn_log WARN home-cache "pool cache folder '$pool_path' is not available; home $id uses RAM"
    fi
    root="$(home_cache_root "$eff" "$pool_path" "$id")"
    if [ "$eff" = "ram" ]; then
        mb="$(home_cache_ram_mb)"
        _hc_ensure_tmpfs "$root" "$mb" "$owner" || {
            cmn_log ERROR home-cache "cannot make the RAM cache for home $id at $root"
            return 1
        }
    else
        mkdir -p "$root" 2>/dev/null || { cmn_log ERROR home-cache "cannot make $root"; return 1; }
        _hc_owner_ok "$owner" && chown "$owner" "$root" 2>/dev/null
    fi
    # The old content on the boot disk goes first (once), before the binds hide it.
    home_cache_clean_once "$id" "$persist" "$home" "$mnt"
    while IFS=$'\t' read -r src rel; do
        [ -n "$src" ] || continue
        # #914: the clean-up above can take seconds. A home removed meanwhile gets no
        # folder, no bind and no state: the RAM cache made for it goes too.
        if ! _hc_home_alive "$persist" "$id"; then
            cmn_log INFO home-cache "home $id was removed while its cache step ran: stop"
            [ "$eff" = "ram" ] && { _hc_umount "$root" 0 && rmdir "$root" 2>/dev/null; }
            rm -f "$(_hc_state_file "$id")" 2>/dev/null
            return 0
        fi
        tgt="$mnt/$rel"
        if _hc_chain_has_symlink "$mnt" "$rel"; then
            cmn_log WARN home-cache "skip $rel of home $id: a symlink lies on its path"
            continue
        fi
        _hc_mkdir_owned "$root" "$rel" "$owner" || { cmn_log WARN home-cache "skip $rel of home $id: no source folder"; continue; }
        _hc_mkdir_owned "$mnt" "$rel" "$owner" || { cmn_log WARN home-cache "skip $rel of home $id: no target folder"; continue; }
        if mountpoint -q "$tgt" 2>/dev/null; then
            a="$(stat -L -c '%d:%i' "$tgt" 2>/dev/null)"; b="$(stat -L -c '%d:%i' "$src" 2>/dev/null)"
            [ -n "$a" ] && [ "$a" = "$b" ] && continue
            _hc_umount "$tgt" 0 || { cmn_log WARN home-cache "keep the old cache bind $tgt: busy ($(_hc_holders "$tgt"))"; continue; }
        fi
        if ! mount --bind "$src" "$tgt" 2>/dev/null; then
            cmn_log WARN home-cache "bind failed: $src -> $tgt (the cache stays on the boot disk)"
        fi
    done < <(home_cache_plan "$eff" "$pool_path" "$mounted" "$id")
    mkdir -p "$(dirname "$(_hc_state_file "$id")")" 2>/dev/null
    printf 'location=%s\nroot=%s\nowner=%s\nmnt=%s\npersist=%s\n' "$eff" "$root" "$owner" "$mnt" "$persist" \
        > "$(_hc_state_file "$id")" 2>/dev/null || true
    lifecycle_log "info" "home_cache" "home_cache_mounted" "{\"id\":\"$id\",\"location\":\"$eff\"}" 2>/dev/null || true
    return 0
}

# home_cache_mount <id> <owner> <mnt> <persist> — apply the settings. The call
# _pt_mount makes after the home bind. Never fails the mount.
home_cache_mount() {
    home_cache_apply "$1" "$2" "$3" "$4" "$(home_cache_location_setting)" "$(home_cache_path_setting)" || true
    return 0
}

# ---- unmount -----------------------------------------------------------------

# home_cache_release_binds <id> <mnt> [lazy_ok] — remove the cache binds of a
# home. 1 when one stays busy (the home mount must then stay too).
home_cache_release_binds() {
    local id="$1" mnt="${2%/}" lazy="${3:-0}" rel rc=0
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        _hc_umount "$mnt/$rel" "$lazy" || rc=1
    done < <(home_cache_dirs)
    return $rc
}

# home_cache_release_ram <id> — after the home bind is gone: unmount the tmpfs and
# forget the state. A tmpfs that is busy stays (it holds only cache).
home_cache_release_ram() {
    local id="$1" dir; dir="$(home_cache_ram_base)/$id"
    if mountpoint -q "$dir" 2>/dev/null; then
        _hc_umount "$dir" "${2:-0}" || return 1
    fi
    rm -f "$(_hc_state_file "$id")" 2>/dev/null
    return 0
}

# home_cache_id_of_mount <mnt> — the home id of ${MOUNT_ROOT}/work/<id>/home.
home_cache_id_of_mount() {
    local m="${1%/}" id
    case "$m" in "${MOUNT_ROOT}/work/"*/home) : ;; *) return 1 ;; esac
    id="${m#"${MOUNT_ROOT}/work/"}"; id="${id%/home}"
    case "$id" in ''|*/*|*[!A-Za-z0-9._-]*) return 1 ;; esac
    printf '%s\n' "$id"
}

# home_cache_reapply <id> — apply the CURRENT settings to a home that has a state
# file (array start, a settings change, a failed unmount). Quiet when none.
home_cache_reapply() {
    local id="$1" owner mnt persist
    mnt="$(_hc_state_get "$id" mnt)"; [ -n "$mnt" ] || return 0
    owner="$(_hc_state_get "$id" owner)"; persist="$(_hc_state_get "$id" persist)"
    mountpoint -q "$mnt" 2>/dev/null || return 0
    home_cache_apply "$id" "$owner" "$mnt" "$persist" "$(home_cache_location_setting)" "$(home_cache_path_setting)" || true
}

home_cache_state_ids() {
    local f
    for f in "$(home_cache_ram_base)"/.state/*; do
        [ -f "$f" ] && basename "$f"
    done
}

home_cache_reapply_all() {
    local id
    for id in $(home_cache_state_ids); do home_cache_reapply "$id"; done
}

# home_cache_repoint_ram_all — AT ARRAY STOP: each home whose caches are on a pool
# gets RAM binds, so no cache bind holds the pool. A bind that stays busy (a
# program has a file open in it) stays: a lazy detach would not free the pool
# either, because the open file still holds it, and it would hide the holder
# (#512). Its holders are logged; Unraid waits for them as for any open file.
home_cache_repoint_ram_all() {
    local id owner mnt persist n=0
    for id in $(home_cache_state_ids); do
        [ "$(_hc_state_get "$id" location)" = "pool" ] || continue
        mnt="$(_hc_state_get "$id" mnt)"; owner="$(_hc_state_get "$id" owner)"; persist="$(_hc_state_get "$id" persist)"
        [ -n "$mnt" ] || continue
        home_cache_apply "$id" "$owner" "$mnt" "$persist" ram "" || true
        n=$((n + 1))
    done
    cmn_log INFO home-cache "array stop: moved the cache binds of $n home(s) to RAM"
    lifecycle_log "info" "home_cache" "home_cache_array_stop" "{\"homes\":$n}" 2>/dev/null || true
    return 0
}

# ---- status and clear (the Storage tab) ----------------------------------------

# home_cache_any_bound <mnt> — 0 when at least one cache folder of the home is a mount.
home_cache_any_bound() {
    local rel
    [ -n "${1:-}" ] || return 1
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        mountpoint -q "${1%/}/$rel" 2>/dev/null && return 0
    done < <(home_cache_dirs)
    return 1
}

# home_cache_status_json <id> <persist> — one JSON object:
# {"location":"ram|pool|boot|none","path":"","bytes":N,"cap_bytes":N,"on_boot_bytes":N}
home_cache_status_json() {
    local id="$1" persist="$2" home loc root bytes=0 cap=0 onboot=0 path="" mnt
    home="$persist/passthrough/homes/$id"
    if [ ! -d "$home" ]; then
        printf '{"location":"none","path":"","bytes":0,"cap_bytes":0,"on_boot_bytes":0}\n'; return 0
    fi
    onboot="$(home_cache_on_boot_bytes "$home")"
    loc="$(_hc_state_get "$id" location)"; root="$(_hc_state_get "$id" root)"; mnt="$(_hc_state_get "$id" mnt)"
    case "$loc" in
        ram|pool)
            if home_cache_any_bound "$mnt"; then
                path="$root"; bytes="$(_hc_du_bytes "$root")"
                [ "$loc" = "ram" ] && cap=$(( $(home_cache_ram_mb) * 1048576 ))
            else
                loc="boot"
            fi ;;
        *) loc="boot" ;;
    esac
    printf '{"location":"%s","path":"%s","bytes":%s,"cap_bytes":%s,"on_boot_bytes":%s}\n' "$loc" "$path" "$bytes" "$cap" "$onboot"
}

# home_cache_clear <id> <persist> — empty every cache folder of a home: the RAM or
# pool source folders and any content left on the boot disk. Refuses (rc 3) while
# a process uses the home. Prints the freed bytes on stdout.
home_cache_clear() {
    local id="$1" persist="$2" home root mnt freed=0 b rel loc
    home="$persist/passthrough/homes/$id"
    mnt="$(_hc_state_get "$id" mnt)"; [ -n "$mnt" ] || mnt="${MOUNT_ROOT}/work/$id/home"
    home_cache_home_busy "$mnt" && return 3
    root="$(_hc_state_get "$id" root)"; loc="$(_hc_state_get "$id" location)"
    if [ -n "$root" ] && [ -d "$root" ] && [ ! -L "$root" ]; then
        case "$root" in "$(home_cache_ram_base)"/*|/mnt/*/*) : ;; *) root="" ;; esac
    else
        root=""
    fi
    if [ -n "$root" ]; then
        while IFS= read -r rel; do
            [ -n "$rel" ] || continue
            _hc_chain_has_symlink "$root" "$rel" && continue
            [ -d "$root/$rel" ] || continue
            b="$(_hc_du_bytes "$root/$rel")"
            find "$root/$rel" -xdev -mindepth 1 -delete 2>/dev/null || true
            freed=$((freed + b - $(_hc_du_bytes "$root/$rel")))
        done < <(home_cache_dirs)
    fi
    b="$(home_cache_wipe_boot "$home")"
    freed=$((freed + b))
    [ "$freed" -lt 0 ] && freed=0
    lifecycle_log "info" "home_cache" "home_cache_cleared" "{\"id\":\"$id\",\"freed_bytes\":$freed}" 2>/dev/null || true
    printf '%s\n' "$freed"
}
