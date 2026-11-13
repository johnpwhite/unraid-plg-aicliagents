#!/bin/bash
# aicli-guard-view.sh: the hidden view of a helper workspace (docs/specs/SHFS_RECURSIVE_READ_GUARD.md, layer 1, issue #972).
# It runs as root INSIDE a new private mount space (the caller uses `unshare --mount=<file>`), then starts the command.
#
# Steps:
#   1. Every mount becomes rprivate: a later host mount does not appear here.
#   2. Each path to bind back (--bind=<ro|rw>|<source>|<target>) is staged first: the source is bound to a staging folder.
#      The source is a pool folder for a pool-only share, else the share path itself.
#   3. An empty tmpfs (mode 755) covers each guarded root (--cover=<root>). A root that is not a folder is skipped.
#   4. The folder chain of each target is made in the tmpfs and the staged path is moved onto it. A "ro" bind is remounted
#      read-only. Each tmpfs is then remounted read-only.
#   5. Each --hide=<folder> gets an empty read-only tmpfs (ssh keys of root: no ssh to the host itself).
#   6. Check with findmnt: each cover is a read-only tmpfs; each bound target is a mount point with the type of its source.
#      A failed check stops the start (the command never runs; exit 70, the reason on stderr).
#   7. The capabilities of --drop-caps leave the bounding set and no-new-privileges is set (setpriv), then the command runs.
#      Without that, root in the view could umount the cover or enter the host with nsenter or /proc/1/root.
#
# Not done here (spec R10, listed in the spec): a new PID space with its own /proc. The host tracks agent processes by their
# host pid (ProcessManager, the stop and close paths), so a new PID space would break them.
#
# Usage: aicli-guard-view.sh [--cover=<root>]... [--bind=<ro|rw>|<source>|<target>]... [--hide=<folder>]...
#        [--setpriv=<path> --drop-caps=<-cap,-cap,...>] [--stage-base=<folder>] -- <command...>
set -euo pipefail
umask 022

covers=()
binds=()
hides=()
setpriv_bin=''
drop_caps=''
stage_base='/dev/shm'

log() { printf '[aicli-guard-view] %s\n' "$*" >&2; }
fail() { log "failed: $*"; exit 70; }
safe_path() { [[ "$1" == /* && "$1" != *'/../'* && "$1" != */.. && "$1" != *'//'* && "$1" != '/' && ! "$1" =~ [[:cntrl:]] && "$1" != *'|'* ]]; }

while (($#)); do
    case "$1" in
        --cover=*) covers+=("${1#--cover=}") ;;
        --bind=*) binds+=("${1#--bind=}") ;;
        --hide=*) hides+=("${1#--hide=}") ;;
        --setpriv=*) setpriv_bin=${1#--setpriv=} ;;
        --drop-caps=*) drop_caps=${1#--drop-caps=} ;;
        --stage-base=*) stage_base=${1#--stage-base=} ;;
        --) shift; break ;;
        *) printf '[aicli-guard-view] invalid option: %s\n' "$1" >&2; exit 64 ;;
    esac
    shift
done
(($# > 0)) || fail 'the command to start is missing'
((${#covers[@]} > 0)) || fail 'no guarded root to cover'
for c in "${covers[@]}"; do safe_path "$c" || fail "a root to cover is not a safe path: $c"; done

modes=(); sources=(); targets=(); types=()
for entry in "${binds[@]}"; do
    mode=${entry%%|*}; rest=${entry#*|}
    src=${rest%%|*}; dst=${rest#*|}
    [[ "$mode" == ro || "$mode" == rw ]] || fail "a bind has no valid mode: $entry"
    safe_path "$src" || fail "a bind source is not a safe path: $src"
    safe_path "$dst" || fail "a bind target is not a safe path: $dst"
    modes+=("$mode"); sources+=("$src"); targets+=("$dst")
done
for h in "${hides[@]}"; do safe_path "$h" || fail "a folder to hide is not a safe path: $h"; done

mount --make-rprivate / || fail 'could not make the mount tree private'

# unescape: findmnt -r writes a space as \x20.
fstype_of() { findmnt -n -r -o FSTYPE --target "$1" 2>/dev/null | head -n 1; }

# ---- 2. Stage the sources before the roots are covered ----
stage=''
if ((${#sources[@]} > 0)); then
    stage=$(mktemp -d "$stage_base/aicli-gview.XXXXXX") || fail 'could not make the staging folder'
    chmod 0700 "$stage"
    for i in "${!sources[@]}"; do
        src=${sources[$i]}
        # A network path may be dead: the check is bounded.
        timeout 10 stat -- "$src" >/dev/null 2>&1 || fail "a path to bind does not exist or does not answer: $src"
        types[$i]=$(timeout 10 findmnt -n -r -o FSTYPE --target "$src" 2>/dev/null | head -n 1 || true)
        [[ -n "${types[$i]}" ]] || fail "the file system type of a path to bind is unknown: $src"
        if [[ -d "$src" ]]; then mkdir "$stage/$i"; else : > "$stage/$i"; fi
        mount --rbind "$src" "$stage/$i" || fail "could not keep a path to bind: $src"
    done
fi

# ---- 3. Cover each guarded root with an empty tmpfs ----
covered=()
for c in "${covers[@]}"; do
    [[ -d "$c" ]] || continue
    mount -t tmpfs -o mode=0755,size=4m,nosuid,nodev tmpfs "$c" || fail "could not cover the guarded root: $c"
    covered+=("$c")
done
((${#covered[@]} > 0)) || fail 'no guarded root exists to cover'

# ---- 4. Rebuild the folder chains and move the staged paths back ----
for i in "${!sources[@]}"; do
    dst=${targets[$i]}
    inside=0
    for c in "${covered[@]}"; do [[ "$dst" == "$c"/* ]] && inside=1; done
    if ((inside)); then
        # Under a covered root: a parent that an earlier bind brought already holds the target; else make the chain in the tmpfs.
        if [[ ! -e "$dst" ]]; then
            mkdir -p -- "$(dirname -- "$dst")" || fail "could not make the folder chain of: $dst"
            if [[ -d "$stage/$i" ]]; then mkdir -- "$dst" || fail "could not make the target: $dst"; else : > "$dst" || fail "could not make the target file: $dst"; fi
        fi
    else
        [[ -e "$dst" ]] || fail "a bind target outside the covered roots is missing: $dst"
    fi
    mount --move "$stage/$i" "$dst" || fail "could not bind back: $dst"
    if [[ "${modes[$i]}" == ro ]]; then
        mount -o remount,bind,ro -- "$dst" || fail "could not make a bound path read-only: $dst"
    fi
done
if [[ -n "$stage" ]]; then rm -rf --one-file-system -- "$stage" 2>/dev/null || true; fi
for c in "${covered[@]}"; do
    mount -o remount,bind,ro -- "$c" || fail "could not make the cover read-only: $c"
done

# ---- 5. Hide the ssh keys ----
hidden=()
for h in "${hides[@]}"; do
    [[ -d "$h" ]] || continue
    mount -t tmpfs -o ro,mode=0700,size=64k,nosuid,nodev,noexec tmpfs "$h" || fail "could not hide: $h"
    hidden+=("$h")
done

# ---- 6. Check the result ----
for c in "${covered[@]}"; do
    line=$(findmnt -n -r -o FSTYPE,OPTIONS -M "$c" 2>&1) || fail "the cover is not a mount point: $c"
    ft=${line%% *}; opts=${line#* }
    [[ "$ft" == tmpfs && ",$opts," == *,ro,* ]] || fail "the cover is not a read-only tmpfs: $c ($line)"
done
for i in "${!targets[@]}"; do
    dst=${targets[$i]}
    line=$(findmnt -n -r -o FSTYPE,OPTIONS -M "$dst" 2>&1) || fail "a bound path is not a mount point: $dst"
    ft=${line%% *}; opts=${line#* }
    [[ "$ft" == "${types[$i]}" ]] || fail "a bound path has the type $ft, not the type ${types[$i]} of its source: $dst"
    if [[ "${modes[$i]}" == ro ]]; then [[ ",$opts," == *,ro,* ]] || fail "a read-only bound path is writable: $dst"; fi
done
for h in "${hidden[@]}"; do
    ft=$(findmnt -n -r -o FSTYPE -M "$h" 2>&1) || fail "a hidden folder is not a mount point: $h"
    [[ "$ft" == tmpfs ]] || fail "a hidden folder is not a tmpfs: $h"
done
log "ready: covered=${#covered[@]} bound=${#targets[@]} hidden=${#hidden[@]}"

# ---- 7. Drop the capabilities, set no-new-privileges, start the command ----
if [[ -n "$setpriv_bin" ]]; then
    [[ -x "$setpriv_bin" ]] || fail 'setpriv is missing'
    [[ -n "$drop_caps" && "$drop_caps" =~ ^(-[a-z_]+)(,-[a-z_]+)*$ ]] || fail 'the capability list is not valid'
    exec "$setpriv_bin" --no-new-privs --bounding-set="$drop_caps" -- "$@"
fi
exec "$@"
