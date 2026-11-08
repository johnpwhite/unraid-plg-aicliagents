#!/bin/bash

# Build the private disk view of the restricted run user (docs/specs/AGENT_RUN_ENTRY_POINT.md R17), then run the command in it.
# It runs as root, inside a new private mount space (the caller uses `unshare --mount`). The command starts the tmux server
# of the run, so every process of the run stays in this view.
#
# What the view is:
#   1. A private tmpfs hides everything below /mnt. Only the run folder and the folders named with --read-folder are bound
#      back. Listed folders are read-only. The run folder is writable when --writable=1.
#   2. Every mount below /boot and /usr/local/emhttp is read-only (a folder that is not a mount point is bound to itself
#      RECURSIVELY first, so the mounts below it stay).
#   3. /tmp is a private tmpfs. The plugin state tree (the --state-root folder) is a private tmpfs too, and only the paths
#      named with --rw and --ro are bound back into it. Root's home, the sockets, the logs and the other runs are not there.
#   4. Every other folder of the host that all users can write (/var/tmp, /var/lock, /dev/shm, ...) is covered by a private
#      tmpfs, so a write there does not reach the host and a planted link there cannot be followed by a root program.
#   5. /var/run (the control socket of Unraid, the terminal sockets) and /var/local (the Unraid state with the csrf token)
#      are empty and read-only.
#   6. Before the command starts, the script checks only the listed roots under /mnt plus /boot and /usr/local/emhttp. It
#      stops (the command never starts) when a mount is writable, except the run folder.
#
# With --prepare this is the small view of the preparation child: no run folder, everything read-only, and only the paths named
# with --rw writable.
#
# Usage: aicli-run-mounts.sh (--folder=<path> [--writable=0|1] | --prepare) --state-root=<path> --resolv-file=<path> [--read-folder=<path>]... [--rw=<path>]... [--ro=<path>]... [--expect-id=<dev:ino>=<path>]... [--protect=<path>]... -- <command...>
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-mounts: the test switch is ignored for root without the CI marker" >&2
fi
umask 022

folder=''
prepare=0
writable=0
state_root=''   # required: --state-root=<path> (the caller passes the resolved state root; no live path is spelled out here)
resolv_file=''
mount_root='/mnt'
boot_target='/boot'
emhttp_target='/usr/local/emhttp'
tmp_target='/tmp'
stage_base='/dev/shm'
dev_target='/dev'
sys_target='/sys'
proc_target='/proc'
resolv_target='/etc/resolv.conf'
spool_target='/var/spool'
hide_dirs=(/var/run /var/local)
scan_roots=(/var /dev /etc /usr /opt /srv /root /home)
cover_dirs=(/var/tmp /var/lock /var/spool/samba /var/spool/mail /dev/shm)
rw_paths=()
ro_paths=()
protect_paths=()
read_paths=()
declare -A expect_ids=()   # path -> "dev:ino" that the caller read when it checked owner, mode and link count

log() { printf '[aicli-run-mounts] %s\n' "$*" >&2; }
fail() { log "failed: $*"; exit 70; }

while (($#)); do
    case "$1" in
        --folder=*) folder=${1#--folder=} ;;
        --prepare) prepare=1 ;;
        --writable=0|--writable=1) writable=${1#--writable=} ;;
        --state-root=*) state_root=${1#--state-root=} ;;
        --resolv-file=*) resolv_file=${1#--resolv-file=} ;;
        --rw=*) rw_paths+=("${1#--rw=}") ;;
        --ro=*) ro_paths+=("${1#--ro=}") ;;
        --expect-id=*)
            expect=${1#--expect-id=}
            [[ "$expect" =~ ^([0-9]+:[0-9]+)=(/.+)$ ]] || { printf '[aicli-run-mounts] invalid option: %s\n' "$1" >&2; exit 64; }
            expect_ids["${BASH_REMATCH[2]}"]=${BASH_REMATCH[1]}
            ;;
        --protect=*) protect_paths+=("${1#--protect=}") ;;
        --read-folder=*) read_paths+=("${1#--read-folder=}") ;;
        --) shift; break ;;
        *) printf '[aicli-run-mounts] invalid option: %s\n' "$1" >&2; exit 64 ;;
    esac
    shift
done

if [[ -n "${AICLI_RUN_MOUNTS_TEST_ROOT:-}" ]]; then
    [[ "${AICLI_TEST_TMP:-}" == 1 ]] || fail 'the test root needs the CI test marker'
    test_root=$(realpath -e -- "$AICLI_RUN_MOUNTS_TEST_ROOT") || fail 'the test root cannot be resolved'
    mount_root="$test_root/mnt"
    boot_target="$test_root/boot"
    emhttp_target="$test_root/usr/local/emhttp"
    tmp_target="$test_root/tmp"
    stage_base="$test_root/stage"
    hide_dirs=("$test_root/var/run" "$test_root/var/local")
    scan_roots=("$test_root/var" "$test_root/dev")
    cover_dirs=("$test_root/var/tmp" "$test_root/var/lock" "$test_root/dev/shm")
    dev_target="$test_root/dev"
    sys_target="$test_root/sys"
    proc_target="$test_root/proc"
    resolv_target="$test_root/etc/resolv.conf"
    spool_target="$test_root/var/spool"
    mkdir -p "$stage_base" "$tmp_target" "$spool_target" "$sys_target" "$dev_target" "$proc_target" "$(dirname "$resolv_target")"
fi

safe_path() {
    # An absolute path with no "..", no empty part and no control character.
    [[ "$1" == /* && "$1" != *'/../'* && "$1" != */.. && "$1" != *'//'* && ! "$1" =~ [[:cntrl:]] ]]
}

if ((prepare)); then
    ((${#read_paths[@]} == 0)) || fail 'the preparation view cannot include listed folders'
    folder=''
    writable=0
else
    [[ "$folder" == "$mount_root"/* && -d "$folder" && ! -L "$folder" ]] || fail 'the run folder is not a plain directory under the mount root'
    original_folder=$folder
    folder=$(realpath -e -- "$folder") || fail 'the run folder cannot be resolved'
    [[ "$folder" == "$original_folder" && "$folder" == "$mount_root"/* ]] || fail 'the run folder passes a link or is outside the mount root'
fi
mount_root_real=$(realpath -e -- "$mount_root") || fail 'the mount root cannot be resolved'
mount_prefix="${mount_root_real%/}/"
for p in "${read_paths[@]}"; do
    safe_path "$p" || fail "a listed folder is not a safe path: $p"
    [[ "$p" == "$mount_root_real"/* && -d "$p" && ! -L "$p" ]] || fail "a listed folder is missing or outside the mount root: $p"
    [[ "$(realpath -e -- "$p")" == "$p" ]] || fail "a listed folder passes a link: $p"
    [[ "$p" == "$mount_prefix"* ]] || fail "a listed folder is outside the mount root: $p"
done
if ((prepare)); then
    view_paths=()
    view_modes=()
else
    view_paths=()
    view_modes=()
    add_view_path() {
        local mode=$1 p=$2 i
        for i in "${!view_paths[@]}"; do
            if [[ "${view_paths[$i]}" == "$p" ]]; then
                [[ "$mode" != rw ]] || view_modes[$i]=rw
                return
            fi
        done
        view_paths+=("$p")
        view_modes+=("$mode")
    }
    for p in "${read_paths[@]}"; do add_view_path ro "$p"; done
    add_view_path "$([[ "$writable" == 1 ]] && printf rw || printf ro)" "$folder"
    for ((i=1; i<${#view_paths[@]}; i++)); do
        j=$i
        while ((j > 0)); do
            old=${view_paths[$((j-1))]}
            current=${view_paths[$j]}
            old_slashes=${old//[^\/]/}
            old_depth=${#old_slashes}
            current_slashes=${current//[^\/]/}
            current_depth=${#current_slashes}
            if ((current_depth > old_depth)) || { ((current_depth == old_depth)) && ((${#current} > ${#old})); }; then break; fi
            tmp_path=${view_paths[$((j-1))]}; view_paths[$((j-1))]=${view_paths[$j]}; view_paths[$j]=$tmp_path
            tmp_mode=${view_modes[$((j-1))]}; view_modes[$((j-1))]=${view_modes[$j]}; view_modes[$j]=$tmp_mode
            ((j -= 1))
        done
    done
fi
safe_path "$state_root" || fail 'the state root (--state-root) is missing or is not a safe path'
(($# > 0)) || fail 'the command to start is missing'
safe_path "$resolv_file" || fail 'the resolver file (--resolv-file) is missing or is not a safe path'
[[ -f "$resolv_file" && ! -L "$resolv_file" ]] || fail 'the resolver file is missing or is a link'
[[ "$(realpath -e -- "$resolv_file")" == "$resolv_file" ]] || fail 'the resolver file path passes a link'
[[ -f "$resolv_target" ]] || fail 'the resolver target is missing or is not a file'
resolv_target=$(realpath -e -- "$resolv_target") || fail 'the resolver target cannot be resolved'
for dir in "${hide_dirs[@]}" "$tmp_target" "$state_root"; do
    [[ "$resolv_target" != "$dir" && "$resolv_target" != "$dir"/* ]] || fail 'the resolver target would be hidden in the private view'
done

# Every path to bind back must be below the state root, a real file or folder, and not a link at any level.
binds=()      # "<mode>|<path>"
bind_ids=()   # device and inode captured during the safe-path check
for p in "${rw_paths[@]}"; do binds+=("rw|$p"); done
for p in "${ro_paths[@]}"; do binds+=("ro|$p"); done
for entry in "${binds[@]}"; do
    p=${entry#*|}
    safe_path "$p" || fail "a path to bind back is not safe: $p"
    [[ "$p" == "$state_root"/* ]] || fail "a path to bind back is outside the state root: $p"
    [[ -e "$p" && ! -L "$p" ]] || fail "a path to bind back is missing or a link: $p"
    [[ "$(realpath -e -- "$p")" == "$p" ]] || fail "a path to bind back passes a link: $p"
    source_id=$(stat -Lc '%d:%i' -- "$p") || fail "could not check a path to bind back: $p"
    # L2: the caller checked owner, mode and link count of this file. It must be the same file (device and inode) now.
    if [[ -n "${expect_ids[$p]+x}" ]]; then
        [[ "${expect_ids[$p]}" == "$source_id" ]] || fail "a path to bind back is not the file that was checked: $p"
    fi
    bind_ids+=("$source_id")
done

for p in "${!expect_ids[@]}"; do
    found=0
    for entry in "${binds[@]}"; do [[ "${entry#*|}" == "$p" ]] && found=1; done
    ((found)) || fail "an expected identity names a path that is not bound back: $p"
done

mount --make-rprivate / || fail 'could not make the mount tree private'

# ---- 1. Keep the paths to bind back: they are moved out of the way before /tmp is covered ----
stage=''
if ((${#binds[@]} > 0)); then
    stage=$(mktemp -d "$stage_base/aicli-view.XXXXXX") || fail 'could not make the staging folder'
    chmod 0700 "$stage"
    i=0
    for entry in "${binds[@]}"; do
        p=${entry#*|}
        source_id=${bind_ids[$i]}
        if [[ -d "$p" ]]; then mkdir "$stage/$i"; else : > "$stage/$i"; fi
        mount --rbind "$p" "$stage/$i" || fail "could not keep a path to bind back: $p"
        if [[ -L "$p" || "$(realpath -e -- "$p")" != "$p" \
            || "$(stat -Lc '%d:%i' -- "$p")" != "$source_id" \
            || "$(stat -Lc '%d:%i' -- "$stage/$i")" != "$source_id" ]]; then
            umount -- "$stage/$i" 2>/dev/null || true
            fail "a path changed while it was staged: $p"
        fi
        ((i += 1))
    done
fi
view_stage=''
if ((${#view_paths[@]} > 0)); then
    view_stage=$(mktemp -d "$stage_base/aicli-mnt.XXXXXX") || fail 'could not make the listed-folder staging folder'
    chmod 0700 "$view_stage"
    for i in "${!view_paths[@]}"; do
        p=${view_paths[$i]}
        source_id=$(stat -Lc '%d:%i' -- "$p") || fail "could not check a listed folder: $p"
        mkdir "$view_stage/$i"
        mount --rbind "$p" "$view_stage/$i" || fail "could not stage a listed folder: $p"
        [[ "$(realpath -e -- "$p")" == "$p" && "$(stat -Lc '%d:%i' -- "$p")" == "$source_id" \
            && "$(stat -Lc '%d:%i' -- "$view_stage/$i")" == "$source_id" ]] || fail "a listed folder changed while it was staged: $p"
    done
fi

# ---- 2. A private /tmp and a private state tree ----
mount -t tmpfs -o mode=1777,size=256m,nosuid,nodev tmpfs "$tmp_target" || fail 'could not make a private /tmp'
if [[ "$state_root" != "$tmp_target"/* ]]; then
    [[ -d "$state_root" ]] || fail "the state root is missing: $state_root"
    mount -t tmpfs -o mode=1777,size=256m,nosuid,nodev tmpfs "$state_root" || fail 'could not make a private state tree'
else
    mkdir -p "$state_root"
fi
chmod 1777 "$state_root"
if ((${#binds[@]} > 0)); then
    i=0
    for entry in "${binds[@]}"; do
        mode=${entry%%|*}
        p=${entry#*|}
        mkdir -p "$(dirname "$p")"
        if [[ -d "$stage/$i" ]]; then mkdir -p "$p"; else : > "$p"; fi
        mount --move "$stage/$i" "$p" || fail "could not bind back: $p"
        if [[ "$mode" == ro ]]; then
            mount -o remount,bind,ro -- "$p" || fail "could not make a bound path read-only: $p"
        fi
        ((i += 1))
    done
    rm -rf --one-file-system -- "$stage" 2>/dev/null || true
fi

# ---- 3. A private /mnt with only the approved folders ----
# The staging binds hold the original sources before the tmpfs hides every other path under /mnt.
mount -t tmpfs -o mode=0711,size=64m,nosuid,nodev tmpfs "$mount_root" || fail 'could not hide unlisted folders under the mount root'
for i in "${!view_paths[@]}"; do
    p=${view_paths[$i]}
    relative=${p#"$mount_root_real"/}
    target="$mount_root/$relative"
    parent=$(dirname -- "$target")
    mkdir -m 0711 -p -- "$parent"
    if [[ ! -e "$target" ]]; then mkdir -m 0711 -- "$target"; fi
    [[ -d "$target" && ! -L "$target" ]] || fail "a listed folder target is not a directory: $target"
    mount --move "$view_stage/$i" "$target" || fail "could not expose an approved folder: $p"
done
if [[ -n "$view_stage" ]]; then rm -rf --one-file-system -- "$view_stage" 2>/dev/null || true; fi

# ---- 4. Every mount below /boot and /usr/local/emhttp, read-only ----
# A root that is not a mount point is bound to itself with --rbind (recursive): the mounts below it (the agents under
# /usr/local/emhttp) stay in the view. A plain --bind would drop them.
roots=("$boot_target" "$emhttp_target")
for root in "${roots[@]}"; do
    [[ -d "$root" ]] || fail "a root of the read-only view is missing: $root"
    if ! mountpoint -q -- "$root"; then
        mount --rbind "$root" "$root" || fail "could not bind the root recursively: $root"
    fi
done

# The network namespace does not use `ip netns exec` to inject a resolver file.
# Bind the private resolver directly so the persistent mount space sees DNS.
mount --bind "$resolv_file" "$resolv_target" || fail 'could not bind the private resolver file'
mount -o remount,bind,ro -- "$resolv_target" || fail 'could not make the resolver file read-only'

# unescape: findmnt -r writes a space as \x20 and a backslash as \x5c.
unescape() { printf -v "$2" '%b' "$1"; }

# -r: raw output. One line for each mount, columns split by one space, no tree glyphs and no padding. (The plain list -l pads
# the first column with spaces, and the tree draws glyphs; both break a parser that splits the line.)
targets=("$mount_root")
mnt_raw=$(findmnt -R -r -n -o TARGET "$mount_root" 2>&1) || fail "could not list mounts in the private mount root: $mnt_raw"
while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    [[ "$line" == /* ]] || fail "the mount list holds a line that is not a path: $line"
    unescape "$line" target
    targets+=("$target")
done <<< "$mnt_raw"
for root in "${roots[@]}"; do
    raw=$(findmnt -R -r -n -o TARGET "$root" 2>&1) || fail "could not list mounts below $root: $raw"
    targets+=("$root")
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        [[ "$line" == /* ]] || fail "the mount list holds a line that is not a path: $line"
        unescape "$line" target
        targets+=("$target")
    done <<< "$raw"
done

declare -A seen=()
sorted=()
for target in "${targets[@]}"; do
    [[ -z "${seen[$target]+x}" ]] || continue
    seen[$target]=1
    slashes=${target//[^\/]/}
    depth=${#slashes}
    index=0
    while ((index < ${#sorted[@]})); do
        old=${sorted[$index]}
        old_slashes=${old//[^\/]/}
        old_depth=${#old_slashes}
        if ((depth > old_depth)) || { ((depth == old_depth)) && ((${#target} > ${#old})); }; then break; fi
        ((index += 1))
    done
    sorted=("${sorted[@]:0:index}" "$target" "${sorted[@]:index}")
done

for target in "${sorted[@]}"; do
    [[ -e "$target" ]] || fail "a mount target is missing: $target"
    if [[ -d "$target" ]]; then
        mountpoint -q -- "$target" || fail "a mount target is not a mount point: $target"
    else
        # A file mounted over a file. It is a mount point, or it cannot be made read-only.
        mountpoint -q -- "$target" || fail "a mount target is a file that is not a mount point: $target"
    fi
    if [[ "$target" == "$mount_root" ]]; then
        mount -o remount,ro -- "$target" || fail 'could not make the private mount root read-only'
    else
        mount -o remount,bind,ro -- "$target" || fail "could not make mount target read-only: $target"
    fi
done

if [[ "$writable" == 1 ]]; then
    if ! mountpoint -q -- "$folder"; then
        mount --bind "$folder" "$folder" || fail 'could not bind the run folder'
    fi
    mount -o remount,bind,rw -- "$folder" || fail 'could not make the run folder writable'
fi

# Keep the run marker and may-change launch files read-only after the approved folder becomes writable.
for target in "${protect_paths[@]}"; do
    safe_path "$target" || fail 'a protected path is not safe'
    [[ "$target" == "$folder"/* && -e "$target" && ! -L "$target" ]] || fail 'a protected path is missing or outside the run folder'
    [[ "$(realpath -e -- "$target")" == "$target" ]] || fail 'a protected path passes a link'
    mount --bind "$target" "$target" || fail "could not protect a run-folder path: $target"
    mount -o remount,bind,ro -- "$target" || fail "could not make a protected path read-only: $target"
    mountpoint -q -- "$target" || fail "a protected path is not a mount point: $target"
    options=$(findmnt --noheadings --output OPTIONS --target "$target" 2>&1) || fail "could not check a protected path: $options"
    case ",$options," in *,ro,*) ;; *) fail "a protected path is writable: $target" ;; esac
done

# ---- 4. Folders that every user can write: a private tmpfs over each ----
covered=()
coverlist=()
for dir in "${cover_dirs[@]}"; do [[ -d "$dir" ]] && coverlist+=("$dir"); done
if ((${#scan_roots[@]} > 0)); then
    while IFS= read -r dir; do
        [[ -n "$dir" ]] && coverlist+=("$dir")
    done < <(find "${scan_roots[@]}" -xdev -maxdepth 4 -type d -perm -0002 2>/dev/null | sort)
fi
n=0
for dir in "${coverlist[@]}"; do
    skip=0
    for c in "${covered[@]}"; do [[ "$dir" == "$c" || "$dir" == "$c"/* ]] && skip=1; done
    ((skip)) && continue
    # The three read-only roots keep their content (the plugin folders under /usr/local/emhttp may be writable by all, and
    # they are read-only here anyway). /tmp and the state tree are private already.
    case "$dir" in "$tmp_target"|"$tmp_target"/*|"$state_root"|"$state_root"/*|"$mount_root"|"$mount_root"/*|"$boot_target"|"$boot_target"/*|"$emhttp_target"|"$emhttp_target"/*|"$stage_base"/*) continue ;; esac
    ((n += 1))
    ((n > 300)) && fail 'too many folders that every user can write'
    mount -t tmpfs -o mode=1777,size=64m,nosuid,nodev tmpfs "$dir" || fail "could not cover a folder that every user can write: $dir"
    covered+=("$dir")
done

# ---- 5. Hide the control sockets and the Unraid state ----
for dir in "${hide_dirs[@]}"; do
    [[ -d "$dir" ]] || continue
    real=$(realpath -e -- "$dir") || fail "cannot resolve $dir"
    mount -t tmpfs -o ro,size=64k,mode=0755,nosuid,nodev,noexec tmpfs "$real" || fail "could not hide $dir"
done

# ---- 6. Check the result. The command starts only when every mount below the roots is read-only. ----
for root in "${roots[@]}"; do
    check=$(findmnt -R -r -n -o TARGET,OPTIONS "$root" 2>&1) || fail "could not read the mount table below $root: $check"
    found_root=0
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        [[ "$line" == /* ]] || fail "the mount table holds a line that is not a path: $line"
        options=${line##* }                 # the options hold no space; the target may hold an escaped one (\x20)
        unescape "${line% *}" target
        [[ "$target" == "$root" ]] && found_root=1
        if [[ "$writable" == 1 && "$target" == "$folder" ]]; then continue; fi
        [[ ",$options," == *,ro,* ]] || fail "a mount target is writable: $target"
    done <<< "$check"
    ((found_root)) || fail "the root is not in the mount table: $root"
done

# The /mnt check reads only the mount table under the private tmpfs. It never walks the host's pools or /mnt/user.
mnt_check=$(findmnt -R -r -n -o TARGET,OPTIONS "$mount_root" 2>&1) || fail "could not check the private mount root: $mnt_check"
mnt_root_found=0
declare -A mnt_seen=()
while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    [[ "$line" == /* ]] || fail "the private mount table holds a line that is not a path: $line"
    options=${line##* }
    unescape "${line% *}" target
    mnt_seen[$target]=1
    if [[ "$target" == "$mount_root" ]]; then
        mnt_root_found=1
        fstype=$(findmnt --noheadings --output FSTYPE --target "$mount_root" 2>&1) || fail "could not check the private mount root type: $fstype"
        [[ "$fstype" == tmpfs && ",$options," == *,ro,* ]] || fail 'the private mount root is not a read-only tmpfs'
        continue
    fi
    approved=0
    for p in "${view_paths[@]}"; do
        relative=${p#"$mount_root_real"/}
        view_target="$mount_root/$relative"
        [[ "$target" == "$view_target" || "$target" == "$view_target"/* ]] && approved=1
    done
    ((approved)) || fail "an unlisted mount is visible under the private mount root: $target"
    if [[ "$writable" == 1 && "$target" == "$folder" ]]; then continue; fi
    [[ ",$options," == *,ro,* ]] || fail "a listed folder is writable: $target"
done <<< "$mnt_check"
((mnt_root_found)) || fail 'the private mount root is not in the mount table'
for p in "${view_paths[@]}"; do
    relative=${p#"$mount_root_real"/}
    view_target="$mount_root/$relative"
    [[ -n "${mnt_seen[$view_target]+x}" ]] || fail "an approved folder is not mounted: $view_target"
done

log "ready: private disk view for ${folder:-the preparation} (writable=$writable, listed=${#read_paths[@]}, covered=${#covered[@]})"

# A fresh PID namespace is part of every restricted run. Do not continue if the
# caller accidentally starts this script in the host PID space.
[[ -r "$proc_target/1/status" && -r "$proc_target/self/status" ]] || fail 'the private process table is missing'
# The kernel separates the NSpid levels with a tab ("NSpid:\t1" or "NSpid:\t4321\t1"); the last level must be 1.
grep -Eq '^NSpid:([[:space:]]+[0-9]+)*[[:space:]]+1$' "$proc_target/1/status" || fail 'the run is not the first process in its PID space'
mount -o remount,ro,hidepid=2,nosuid,nodev,noexec "$proc_target" || fail 'the process table could not be made read-only and private'

# cron and at run outside the caller's mount space. Hide every spool file and
# make the replacement read-only. no_new_privs in the caller also disables the
# setuid crontab and at binaries.
[[ -d "$spool_target" ]] || fail 'the scheduler spool folder is missing'
mount -t tmpfs -o ro,size=1m,mode=0555,nosuid,nodev,noexec tmpfs "$spool_target" || fail 'the scheduler spool could not be hidden'

# Do not expose host device nodes such as /dev/kvm, /dev/mem or block devices.
# Device nodes cannot be created in this user namespace (no mknod). Bind only the
# fixed, safe host nodes that tmux and ttyd need; the run user sees no others.
mount -t tmpfs -o mode=0755,size=4m,nosuid,noexec tmpfs "$dev_target" || fail 'the private device folder could not be made'
for device in null zero random urandom tty; do
    [[ -c "/dev/$device" ]] || fail "the safe host device /dev/$device is missing"
    : > "$dev_target/$device" || fail "the private device target for /dev/$device could not be made"
    mount --bind "/dev/$device" "$dev_target/$device" || fail "the safe host device /dev/$device could not be bound"
done
mkdir "$dev_target/pts" "$dev_target/shm" || fail 'the private device folders could not be made'
mount -t devpts -o newinstance,ptmxmode=0666,mode=0620,gid=100,nosuid,noexec devpts "$dev_target/pts" || fail 'private pseudo-terminals could not be made'
ln -s pts/ptmx "$dev_target/ptmx" || fail 'the private pseudo-terminal link could not be made'
ln -s "$proc_target/self/fd" "$dev_target/fd" || fail 'the private file descriptor link could not be made'
ln -s "$proc_target/self/fd/0" "$dev_target/stdin" || fail 'the private stdin link could not be made'
ln -s "$proc_target/self/fd/1" "$dev_target/stdout" || fail 'the private stdout link could not be made'
ln -s "$proc_target/self/fd/2" "$dev_target/stderr" || fail 'the private stderr link could not be made'
mount -t tmpfs -o mode=1777,size=64m,nosuid,nodev tmpfs "$dev_target/shm" || fail 'the private shared memory folder could not be made'

# Hide host hardware and cgroup controls. The run can use no host device tree.
[[ -d "$sys_target" ]] || fail 'the system folder is missing'
mount -t tmpfs -o ro,size=64k,mode=0555,nosuid,nodev,noexec tmpfs "$sys_target" || fail 'the system folder could not be hidden'

exec "$@"
