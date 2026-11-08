#!/bin/bash
# Show a restricted workspace ONLY its own per-workspace folders (docs/specs/AGENT_RUN_ENTRY_POINT.md R17, R18; #929).
#
# All restricted workspaces run as one Linux user (aicli-run). Four host parents hold one folder for each workspace.
# Three of them are closed to that user on the host (root-owned, mode 0700):
#   <state>/run-private                 the private flags and environment files:  run-private/<id>
#   <state>/work/aicli-run-sessions     the run-user home:                        aicli-run-sessions/<id>/home
#   <state>/work/aicli-run-tasks        the task folder of a run:                 aicli-run-tasks/<runId>
# The tmux socket folder is hidden by a fourth, different step. <state>/tmux is shared with workspaces that run as other users, so
# it must stay sticky and open. A restricted workspace keeps its socket folder in a root-only parent instead:
#   <state>/run-tmux/s-<id>  (root-owned parent mode 0700, the folder belongs to the run user)
# and root binds that folder onto the empty root-owned mount point <state>/tmux/s-<id>, the path that tmux uses in the view.
#
# This script runs as host root. aicli-run-view.sh starts it with `nsenter --mount=<view>` AFTER the new mount space exists
# and BEFORE the mount helper runs as the mapped run user. At that time the only process in the view is the init script of the
# plugin, so no agent code has run. For each parent the script makes a small tmpfs. It binds only this workspace's folder into
# the tmpfs, at the same relative path, and moves the tmpfs onto the parent IN THIS VIEW ONLY. The host path is unchanged.
# The mount helper then finds its paths where it always did. A bind shows the inode of its source, so the device and inode
# that PHP read on the host are the same ones that the helper checks.
#
# Usage: aicli-run-private-bind.sh <id> <stage-dir> <run-uid> <private-dir> <private-dev:ino> <home-dir> <home-dev:ino> <task-dir|-> <task-dev:ino|-> <tmux-dir> <tmux-dev:ino>
# Exit 70 on any fault, 64 on a bad argument. Nothing partial stays: the caller stops the whole view.
set -euo pipefail
umask 077
# The CI test switch counts only for a process that is not root, or when the CI marker file exists (a root-owned regular file that
# production never has). Root without the marker ignores AICLI_TEST_TMP, so an environment variable cannot relax the root checks.
test_mode=0
if [[ "${AICLI_TEST_TMP:-0}" == 1 ]]; then
    if ((EUID != 0)) || [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
        test_mode=1
    else
        printf 'aicli-run-private-bind: the test switch is ignored for root without the CI marker
' >&2
    fi
fi
if [[ "$test_mode" != 1 ]]; then PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; fi
export PATH

fail() { printf 'aicli-run-private-bind: %s\n' "$*" >&2; exit 70; }
bad() { printf 'aicli-run-private-bind: %s\n' "$*" >&2; exit 64; }

id=${1:-}
stage=${2:-}
run_uid=${3:-}
private_dir=${4:-}
private_ino=${5:-}
home_dir=${6:-}
home_ino=${7:-}
task_dir=${8:--}
task_ino=${9:--}
tmux_dir=${10:-}
tmux_ino=${11:-}
(($# == 11)) || bad 'eleven arguments are needed'

safe_path() { [[ "$1" == /* && "$1" != *'|'* && "$1" != *'/../'* && "$1" != */.. && "$1" != *'//'* && ! "$1" =~ [[:cntrl:]] ]]; }

# The owner of a root-only folder. The test switch (CI only) lets a non-root test user stand in for root.
root_uid=0
min_uid=1000
if [[ "$test_mode" == 1 ]]; then root_uid=$(id -u); min_uid=0; fi

[[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || bad 'invalid workspace id'
[[ "$run_uid" =~ ^[0-9]+$ ]] && ((run_uid >= min_uid)) || bad 'invalid run user id'
view_dir='/var/run/aicli-view'
if [[ "$test_mode" == 1 ]]; then view_dir=$(dirname -- "$stage"); fi
[[ "$stage" == "$view_dir/$id.privstage" ]] || bad 'invalid staging folder'
for ino in "$private_ino" "$home_ino" "$tmux_ino"; do [[ "$ino" =~ ^[0-9]+:[0-9]+$ ]] || bad 'invalid folder identity'; done
safe_path "$private_dir" && safe_path "$home_dir" && safe_path "$tmux_dir" || bad 'a folder is not a safe path'

state_root=${private_dir%"/run-private/$id"}
[[ -n "$state_root" && "$state_root" != "$private_dir" && "$private_dir" == "$state_root/run-private/$id" ]] || bad 'the private folder is not <state>/run-private/<id>'
safe_path "$state_root" || bad 'the state root is not a safe path'
[[ "$home_dir" == "$state_root/work/aicli-run-sessions/$id/home" ]] || bad 'the home is not <state>/work/aicli-run-sessions/<id>/home'
[[ "$tmux_dir" == "$state_root/run-tmux/s-$id" ]] || bad 'the tmux folder is not <state>/run-tmux/s-<id>'
have_task=0
if [[ "$task_dir" != - ]]; then
    safe_path "$task_dir" || bad 'the task folder is not a safe path'
    [[ "$task_ino" =~ ^[0-9]+:[0-9]+$ ]] || bad 'invalid task folder identity'
    run_id=${task_dir##*/}
    [[ "$run_id" =~ ^[A-Za-z0-9]{6,32}$ && "$task_dir" == "$state_root/work/aicli-run-tasks/$run_id" ]] || bad 'the task folder is not <state>/work/aicli-run-tasks/<runId>'
    have_task=1
fi

# One entry for each hidden parent: parent | path below the parent | identity | mode | kind
entries=("$state_root/run-private|$id|$private_ino|ro|private" "$state_root/work/aicli-run-sessions|$id/home|$home_ino|rw|home")
if ((have_task)); then entries+=("$state_root/work/aicli-run-tasks|$run_id|$task_ino|rw|task"); fi

plain_dir() { [[ -d "$1" && ! -L "$1" && "$(realpath -e -- "$1")" == "$1" ]]; }
owner_mode() { stat -c '%u:%a' -- "$1"; }
ident() { stat -L -c '%d:%i' -- "$1"; }

# ---- Check every host path BEFORE any mount. The parent must be root-owned and mode 0700: the run user cannot walk it. ----
for entry in "${entries[@]}"; do
    IFS='|' read -r parent rel want mode kind <<< "$entry"
    plain_dir "$parent" || fail "the parent is not a plain folder: $parent"
    [[ "$(owner_mode "$parent")" == "$root_uid:700" ]] || fail "the parent is not root-owned with mode 0700: $parent"
    ! mountpoint -q -- "$parent" || fail "the parent is a mount point already: $parent"
    top=${rel%%/*}
    if [[ "$top" != "$rel" ]]; then
        plain_dir "$parent/$top" || fail "the workspace folder is not a plain folder: $parent/$top"
        mid=$(owner_mode "$parent/$top")
        [[ "${mid%%:*}" == "$root_uid" && "$(stat -c '%a' -- "$parent/$top")" =~ ^(700|711)$ ]] || fail "the workspace folder is not root-owned: $parent/$top"
    fi
    leaf="$parent/$rel"
    plain_dir "$leaf" || fail "the folder is not a plain folder: $leaf"
    [[ "$(ident "$leaf")" == "$want" ]] || fail "the folder is not the one that was checked: $leaf"
    case "$kind" in
        private)
            [[ "$(owner_mode "$leaf")" == "$root_uid:711" ]] || fail "the private folder is not root-owned with mode 0711: $leaf"
            names=$(ls -A -- "$leaf") || fail "the private folder cannot be listed: $leaf"
            [[ "$names" == $'args.json\nenv.sh' ]] || fail "the private folder holds other entries than args.json and env.sh: $leaf"
            for name in args.json env.sh; do
                f="$leaf/$name"
                [[ -f "$f" && ! -L "$f" && "$(stat -c '%u:%a:%h' -- "$f")" == "$run_uid:400:1" ]] || fail "a private file has the wrong owner, mode or link count: $f"
            done
            ;;
        home|task)
            [[ "$(owner_mode "$leaf")" == "$run_uid:700" ]] || fail "the folder is not owned by the run user with mode 0700: $leaf"
            ;;
    esac
done

# The tmux folder: the root-only parent, the folder of the run user, and the empty root-owned mount point that the view uses.
tmux_parent="$state_root/run-tmux"
tmux_point="$state_root/tmux/s-$id"
plain_dir "$tmux_parent" || fail "the tmux parent is not a plain folder: $tmux_parent"
[[ "$(owner_mode "$tmux_parent")" == "$root_uid:700" ]] || fail "the tmux parent is not root-owned with mode 0700: $tmux_parent"
! mountpoint -q -- "$tmux_parent" || fail 'the tmux parent is a mount point already'
plain_dir "$tmux_dir" || fail "the tmux folder is not a plain folder: $tmux_dir"
[[ "$(ident "$tmux_dir")" == "$tmux_ino" ]] || fail "the tmux folder is not the one that was checked: $tmux_dir"
[[ "$(owner_mode "$tmux_dir")" == "$run_uid:700" ]] || fail "the tmux folder is not owned by the run user with mode 0700: $tmux_dir"
plain_dir "$state_root/tmux" || fail 'the shared tmux folder is not a plain folder'
plain_dir "$tmux_point" || fail "the tmux mount point is not a plain folder: $tmux_point"
[[ "$(stat -c '%u' -- "$tmux_point")" == "$root_uid" ]] && (( (8#$(stat -c '%a' -- "$tmux_point") & 8#022) == 0 )) || fail "the tmux mount point is not a root-owned folder that others cannot change: $tmux_point"
[[ -z "$(ls -A -- "$tmux_point")" ]] || fail "the tmux mount point is not empty: $tmux_point"
! mountpoint -q -- "$tmux_point" || fail "the tmux mount point is a mount point already: $tmux_point"

# ---- Make the bind in this mount space only. ----
mount --make-rprivate / || fail 'could not make the mount tree private'
[[ -d "$stage" && ! -L "$stage" && "$(owner_mode "$stage")" == "$root_uid:700" ]] || fail 'the staging folder is not a root-only folder'
! mountpoint -q -- "$stage" || fail 'the staging folder is a mount point'
[[ -z "$(ls -A -- "$stage")" ]] || fail 'the staging folder is not empty'
# The holder keeps the staging tmpfs mounts off the host file system, so nothing is made inside the host folder.
mount -t tmpfs -o mode=0700,size=64k,nr_inodes=32,nosuid,nodev,noexec aicli-private-stage "$stage" || fail 'could not make the staging tmpfs'

n=0
for entry in "${entries[@]}"; do
    IFS='|' read -r parent rel want mode kind <<< "$entry"
    top=${rel%%/*}
    slot="$stage/$n"
    mkdir -m 0711 -- "$slot"
    mount -t tmpfs -o mode=0711,size=64k,nr_inodes=8,nosuid,nodev,noexec aicli-private "$slot" || fail "could not make the tmpfs for $parent"
    mkdir -m 0711 -- "$slot/$top"
    if [[ "$top" != "$rel" ]]; then mkdir -m 0711 -- "$slot/$rel"; fi
    mount --bind "$parent/$rel" "$slot/$rel" || fail "could not bind the folder: $parent/$rel"
    if [[ "$mode" == ro ]]; then
        mount -o remount,bind,ro,nosuid,nodev,noexec "$slot/$rel" || fail "could not make the folder read-only: $parent/$rel"
    fi
    # The bind shows the inode of its source: the identity must be the one that PHP read, and the source must not have moved.
    [[ "$(ident "$slot/$rel")" == "$want" && "$(ident "$parent/$rel")" == "$want" ]] || fail "the folder changed while it was bound: $parent/$rel"
    mount -o remount,ro "$slot" || fail "could not make the tmpfs read-only for $parent"
    # The tmpfs holds exactly this workspace's top folder, so no other workspace is listed.
    [[ "$(ls -A -- "$slot")" == "$top" ]] || fail "the tmpfs holds other entries than the workspace folder: $parent"
    ((n += 1))
done

n=0
for entry in "${entries[@]}"; do
    IFS='|' read -r parent rel want mode kind <<< "$entry"
    mount --move "$stage/$n" "$parent" || fail "could not move the tmpfs onto the parent: $parent"
    ((n += 1))
done
umount -- "$stage" || fail 'could not remove the staging tmpfs'
rmdir -- "$stage" 2>/dev/null || true
# The tmux folder: a plain writable bind onto the empty mount point. The path in the view stays <state>/tmux/s-<id>.
mount --bind "$tmux_dir" "$tmux_point" || fail "could not bind the tmux folder: $tmux_dir"
[[ "$(ident "$tmux_point")" == "$tmux_ino" && "$(ident "$tmux_dir")" == "$tmux_ino" ]] || fail 'the tmux folder changed while it was bound'

# ---- Check the result in this view. ----
for entry in "${entries[@]}"; do
    IFS='|' read -r parent rel want mode kind <<< "$entry"
    fs=$(findmnt --noheadings --output FSTYPE --mountpoint "$parent" 2>/dev/null || true)
    [[ "$fs" == tmpfs ]] || fail "the parent is not a tmpfs in this view: $parent"
    options=$(findmnt --noheadings --output OPTIONS --mountpoint "$parent" 2>/dev/null || true)
    case ",$options," in *,ro,*) ;; *) fail "the parent tmpfs is not read-only: $parent" ;; esac
    [[ "$(ident "$parent/$rel")" == "$want" ]] || fail "the folder in the view is not the one that was checked: $parent/$rel"
    options=$(findmnt --noheadings --output OPTIONS --mountpoint "$parent/$rel" 2>/dev/null || true)
    [[ -n "$options" ]] || fail "the folder is not a mount point in this view: $parent/$rel"
    if [[ "$mode" == ro ]]; then
        case ",$options," in *,ro,*) ;; *) fail "the folder is not read-only in this view: $parent/$rel" ;; esac
    fi
done
options=$(findmnt --noheadings --output OPTIONS --mountpoint "$tmux_point" 2>/dev/null || true)
[[ -n "$options" ]] || fail "the tmux folder is not a mount point in this view: $tmux_point"
exit 0
