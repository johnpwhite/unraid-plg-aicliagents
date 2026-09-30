#!/bin/bash
# Immutable plugin-generation helpers.
#
# The installer extracts and validates a complete payload before this file is
# sourced.  Activation changes one symlink with one rename; existing processes
# keep using the generation path captured at launch.

_aicli_sanitize_generation_part() {
    printf '%s' "${1:-unknown}" | tr -c 'A-Za-z0-9._-' '_'
}

aicli_payload_generation_id() {
    local archive="$1" version="$2" digest
    digest=$(sha256sum "$archive" 2>/dev/null | awk '{print substr($1,1,16)}')
    [ -n "$digest" ] || return 1
    printf '%s-%s' "$(_aicli_sanitize_generation_part "$version")" "$digest"
}

# aicli_agent_generation_id <layer_path>
#
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09): the generation id for one
# installed agent version, derived THE SAME WAY the plugin derives its own
# (aicli_payload_generation_id: a content hash + a human-readable version tag) —
# reused verbatim, not re-implemented, per the spec's explicit direction. The
# "archive" is the agent's newest baked layer (.sqsh); its own basename already
# carries the {seq10}_{dt} the bake pipeline assigns
# (agent_{id}_consolidated_{seq10}_{dt}.sqsh — see common.sh _layer_sort_key),
# so that basename doubles as the "+version" tag instead of requiring a second,
# separate read of versions.json from bash (this box has no jq guarantee and
# nothing else in the shell mount pipeline parses JSON). Two mounts of the same
# unchanged layer therefore hash to the identical id — exactly the idempotence
# op_mount's caller needs (see storage_ops.sh op_mount): a no-op remount lands
# back on the SAME generation directory instead of accreting a new one.
aicli_agent_generation_id() {
    local layer_path="$1" layer_stem
    [ -n "$layer_path" ] || return 1
    layer_stem="$(basename -- "$layer_path" .sqsh)"
    aicli_payload_generation_id "$layer_path" "$layer_stem"
}

aicli_validate_staged_payload() {
    local staged_root="$1" required
    for required in \
        src/AICliAjax.php \
        src/AICliAgentsManager.page \
        src/AICliAgents.page \
        src/includes/AICliAgentsManager.php \
        src/scripts/aicli-shell.sh \
        src/scripts/user/effective-env-export.php \
        src/scripts/installer/cleanup.sh \
        src/scripts/installer/generation.sh; do
        [ -f "$staged_root/$required" ] || {
            printf 'staged payload missing required file: %s\n' "$required" >&2
            return 1
        }
    done
    bash -n "$staged_root/src/scripts/aicli-shell.sh" || return 1
    bash -n "$staged_root/src/scripts/installer/cleanup.sh" || return 1
    return 0
}

aicli_has_live_sessions() {
    local run_dir="${AICLI_RUN_DIR:-/var/run}" pid_file pid cmdline
    for pid_file in "$run_dir"/unraid-aicliagents-*.pid; do
        [ -f "$pid_file" ] || continue
        pid=$(tr -dc '0-9' < "$pid_file" 2>/dev/null)
        [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null || continue
        cmdline=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)
        case "$cmdline" in
            *ttyd*/*aicliterm-*|*aicli-shell.sh*) return 0 ;;
        esac
        [ "${AICLI_TEST_ALLOW_ANY_PID:-0}" = "1" ] && return 0
    done
    # A tmux pane can outlive its browser-side ttyd listener. Its generated
    # command path is still positive evidence that an agent wrapper is active.
    local work_root="${AICLI_WORK_ROOT:-/tmp/unraid-aicliagents/work}"
    if command -v pgrep >/dev/null 2>&1; then
        pgrep -f "$work_root/.*/aicli-run-[^/]*\\.sh" >/dev/null 2>&1 && return 0
    fi

    # Minimal Unraid/CI environments may not provide procps/pgrep. Do not let
    # a missing convenience binary weaken the migration safety gate: /proc is
    # the authoritative process inventory and is available on the target host.
    local proc_cmdline
    for proc_cmdline in /proc/[0-9]*/cmdline; do
        [ -r "$proc_cmdline" ] || continue
        cmdline=$(tr '\0' ' ' < "$proc_cmdline" 2>/dev/null)
        case "$cmdline" in
            *"$work_root/"*/aicli-run-*.sh*) return 0 ;;
        esac
    done
    return 1
}

aicli_stage_requires_migration() {
    local staged_root="$1" old_version="$2" config_dir="$3" new_version
    new_version=$(tr -d '[:space:]' < "$staged_root/src/.layout-version" 2>/dev/null)
    [[ "$new_version" =~ ^[0-9]+$ ]] || new_version=0
    [[ "$old_version" =~ ^[0-9]+$ ]] || old_version=0
    [ -f "$config_dir/.migration_required" ] || [ "$old_version" != "$new_version" ]
}

aicli_activate_generation() {
    local emhttp_dest="$1" staged_root="$2" generation_id="$3"
    local generations="$emhttp_dest/.generations"
    local generation="$generations/$generation_id"
    local next_link="$emhttp_dest/.src.next.$$"
    local legacy_generation

    mkdir -p "$generations" || return 1
    if [ ! -d "$generation/src" ]; then
        mv "$staged_root" "$generation" || return 1
    fi

    ln -s ".generations/$generation_id/src" "$next_link" || return 1

    # First immutable-generation upgrade: retain the old physical src tree as
    # a generation.  It is never deleted here because a pre-upgrade process may
    # still have paths into it.
    if [ -d "$emhttp_dest/src" ] && [ ! -L "$emhttp_dest/src" ]; then
        legacy_generation="$generations/legacy-$(date +%s)-$$"
        mkdir -p "$legacy_generation" || return 1
        mv "$emhttp_dest/src" "$legacy_generation/src" || return 1
        if ! mv -Tf "$next_link" "$emhttp_dest/src"; then
            mv "$legacy_generation/src" "$emhttp_dest/src" 2>/dev/null || true
            rm -f "$next_link"
            return 1
        fi
    else
        mv -Tf "$next_link" "$emhttp_dest/src" || {
            rm -f "$next_link"
            return 1
        }
    fi
    # Informational marker; src itself is the authoritative atomic pointer.
    printf '%s\n' "$generation_id" > "$emhttp_dest/.active-generation.tmp.$$" \
        && mv -f "$emhttp_dest/.active-generation.tmp.$$" "$emhttp_dest/.active-generation" \
        || true

    # AUTO_RECONNECT_ALL_ON_DEPLOY.md: push a deploy event so every open browser
    # reconnects all its stale terminals onto the new generation promptly,
    # instead of waiting on the ~45s asset/generation poll. Best-effort — a
    # failed publish never affects activation; the poll is the fallback.
    #
    # EVENT_STREAM_MULTIPLEX.md R4: routed through event-publish.php (EventBus::
    # publish() under it) instead of curling nginx's Nchan socket directly, so
    # this shell publish gets the same `ts` stamp, failure counter and ledger
    # tee every PHP publisher gets. The tee (EventLedger::kindForChannel('deploy',
    # ...) always yields 'deploy.activated') writes the ONE ledger row for this
    # activation — there is no separate event-append.php call any more, since
    # that would write a second row for the same event. If the php binary or
    # the script is missing, `|| true` keeps this best-effort, exactly as the
    # old curl call was.
    local deploy_payload="{\"event\":\"activated\",\"generation\":\"$generation_id\"}"
    # Bounded: the old curl had --max-time 2; a PHP boot that hangs (a wedged
    # share) must never hold up an activation.
    timeout 15 php "$emhttp_dest/src/scripts/event-publish.php" deploy "$deploy_payload" >/dev/null 2>&1 || true

    # #331: the Relay HTTP listener must never keep serving an older
    # generation's code. Bring it into the stored state now: an enabled
    # listener moves to this generation, and every listener of an older
    # generation is stopped (also when the listener is turned off). Runs on
    # both activation paths — finalize.sh (plugin install/update) and
    # tools/promote-generation.sh. Best-effort and bounded, like the publish.
    # #337: run it with every inherited fd above 2 closed (the installer's
    # progress pipe fd 4, the install lock fd 9). relay-http-up.sh starts the
    # listener through the same helper, detached; closing here as well means
    # no child of this step can hold `plugin install`'s output open.
    bash "$emhttp_dest/src/scripts/aicli-detach.sh" --run -- \
        timeout 30 php "$emhttp_dest/src/scripts/relay-agent.php" http-listener-reconcile >/dev/null 2>&1 || true

    # Garbage-collect superseded generations now that the new one is active. Safe by
    # construction — never touches the active generation or any still referenced by a
    # live process; keeps a small rollback buffer. Best-effort (never fails activation).
    aicli_gc_generations "$emhttp_dest" || true
}

# Pure selection: from a newest-first list of generation ids on stdin, print the ids to
# REAP — i.e. neither the active id, nor in the referenced set, and beyond the newest
# `keep_recent` unreferenced ones (the rollback buffer). No filesystem access, so the
# policy is unit-testable in isolation. See MULTI_CLIENT_TERMINAL / generation model.
#
#   $1 active_id       the currently-active generation id (never reaped)
#   $2 keep_recent     how many newest UNREFERENCED gens to retain as a buffer
#   $3 referenced      newline-separated ids still referenced by a live process (kept)
aicli_select_reapable_generations() {
    local active="$1" keep_recent="${2:-3}" referenced="$3" gen kept=0
    while IFS= read -r gen; do
        [ -z "$gen" ] && continue
        [ "$gen" = "$active" ] && continue
        printf '%s\n' "$referenced" | grep -qxF -- "$gen" && continue
        kept=$((kept + 1))
        [ "$kept" -le "$keep_recent" ] && continue   # within the rollback buffer — keep
        printf '%s\n' "$gen"
    done
}

# Print the set of generation ids referenced by ANY live process (one per line), read
# from every process's cmdline + environ. A running agent launched under a generation
# keeps absolute paths into it, so a referenced generation must never be reaped.
#
# CRITICAL (incident 2026-08-15, reaped live gens TWICE): `/proc/<pid>/{cmdline,environ}`
# are NUL-delimited. Running `grep` directly over them is unreliable — grep treats them
# as binary and, under process churn (an install spawns many short-lived procs), silently
# produced an INCOMPLETE referenced set, so the GC reaped generations that were in use.
# Reading each file through `tr '\0' '\n'` FIRST (proven reliable across every manual
# verification) is the safe method. $2 scan_dir overrides /proc for unit tests.
#
# Forgejo #367 (2026-09-29): ALSO open files. A PHP process started through the
# `src` link (`php .../src/scripts/relay-mcp.php`, admin-mcp.php, a php-fpm
# worker in a request) names no generation in its cmdline or environ, but PHP
# keeps its main script open by the REAL generation path, and it loads later
# files from that same generation (the pinned include rule). Reaping that tree
# makes its next include fail. Seen on .4 the same day: relay-mcp.php and
# admin-mcp.php held 2026.09.29.04-0919eb07… open while the cmdline/environ scan
# did not list it. `find -printf %l` prints each fd link target in one process
# (no fork per fd); a proc that vanishes mid-scan is skipped.
aicli_referenced_generations() {
    local scan_dir="${1:-/proc}" f g
    {
        for f in "$scan_dir"/[0-9]*/cmdline "$scan_dir"/[0-9]*/environ; do
            [ -r "$f" ] || continue
            # Group-redirect stderr so a proc that vanishes AFTER the -r check (a race during
            # an install's process churn) is silently skipped, not logged. `< "$f"`'s own
            # failure is reported to the group's stderr, so it must be suppressed here.
            { tr '\0' '\n' < "$f"; } 2>/dev/null \
                | grep -oE '\.generations/[0-9]+\.[0-9.]+-[a-f0-9]+' \
                | sed 's|^\.generations/||'
        done
        find "$scan_dir"/[0-9]*/fd -mindepth 1 -maxdepth 1 -type l -lname '*.generations/*' \
                -printf '%l\n' 2>/dev/null \
            | grep -oE '\.generations/[0-9]+\.[0-9.]+-[a-f0-9]+' \
            | sed 's|^\.generations/||'
    } | sort -u
}

# True if this specific generation is referenced by a live process. Thin wrapper over
# aicli_referenced_generations so both share the reliable tr-based scan.
#
# NEVER pipe the scan straight into `grep -q`. `grep -q` exits the instant it
# matches and closes the pipe, the scan upstream takes SIGPIPE, and under
# `set -o pipefail` — which every storage op runs with — the pipeline's status is
# that failure. The result is a function that returns "not referenced" precisely
# when the generation IS referenced: the exact inversion that makes a GC reap a
# live generation. Read the scan into a variable and compare without a pipeline.
# (Found 2026-09-15 by the Phase 3 end-to-end test, after the unit tests passed:
# they did not set pipefail, and the callers do.)
aicli_generation_is_referenced() {
    local gen="$1" scan_dir="${2:-/proc}" refs line
    [ -n "$gen" ] || return 1
    refs="$(aicli_referenced_generations "$scan_dir")"
    while IFS= read -r line; do
        [ "$line" = "$gen" ] && return 0
    done <<< "$refs"
    return 1
}

# Reap superseded generation dirs. Keeps: the active one, every generation still
# referenced by a live process, and the newest `keep_recent` unreferenced ones. Best
# effort; prints a one-line summary.
#   $1 emhttp_dest    plugin root (holds src symlink + .generations/)
#   $2 keep_recent    rollback buffer size (default 3)
aicli_gc_generations() {
    local emhttp_dest="$1" keep_recent="${2:-3}" scan_dir="${3:-/proc}"
    local gens_dir="$emhttp_dest/.generations"
    [ -d "$gens_dir" ] || return 0

    local active_gen referenced gen removed=0
    active_gen=$(basename "$(dirname "$(readlink "$emhttp_dest/src" 2>/dev/null)")" 2>/dev/null)

    # ONE reliable pass builds the whole referenced set (per-gen re-scans raced).
    referenced=$(aicli_referenced_generations "$scan_dir")

    # SAFETY FLOOR: if the scan found NO referenced generation while agent wrappers are
    # live, the scan is untrustworthy (the 2026-08-15 failure mode) — refuse to reap
    # ANYTHING rather than risk removing a generation still in use.
    if [ -z "$referenced" ] && pgrep -f 'aicli-run-[a-z0-9]+\.sh' >/dev/null 2>&1; then
        printf 'gc_generations: ABORT — 0 referenced gens found but agents are live; refusing to reap\n' >&2
        return 0
    fi

    # Feed ids newest-first (by mtime) to the pure selector, then remove the result.
    # Extra guard: never rm the active gen or a still-referenced one, even if a future
    # selector change slipped (belt and suspenders on a destructive op).
    while IFS= read -r gen; do
        [ -n "$gen" ] || continue
        [ "$gen" = "$active_gen" ] && continue
        printf '%s\n' "$referenced" | grep -qxF -- "$gen" && continue
        rm -rf "${gens_dir:?}/${gen:?}" 2>/dev/null && removed=$((removed + 1))
    done < <(ls -1dt "$gens_dir"/*/ 2>/dev/null | while IFS= read -r d; do basename "$d"; done \
                | aicli_select_reapable_generations "$active_gen" "$keep_recent" "$referenced")

    printf 'gc_generations: reaped %d superseded generation(s); kept active=%s + %d referenced + %d buffer\n' \
        "$removed" "$active_gen" "$(printf '%s' "$referenced" | grep -c .)" "$keep_recent"
}

# ---- Agent generation reference counting + GC --------------------------------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15). The plugin's
# own generations (above) and an agent's generations are the same problem with a
# different path grammar, so the PURE selector is reused verbatim
# (aicli_select_reapable_generations) rather than re-derived — the spec is
# explicit about that, and a second copy of the policy is exactly how the two
# would drift.
#
# What differs is the scan pattern and the cost of getting it wrong. A reaped
# plugin generation is a directory tree; a reaped agent generation is a live
# overlay mount plus a writable upper of 100-450 MB on a write-endurance-limited
# stick. So the floor here is stricter: keep_recent defaults to 0 (an agent has
# no rollback buffer — that is what the retained-backup feature is for), and a
# generation is kept whenever ANY live process so much as names its path.
#
# On the #164 lesson: classifyExternalBinaryHolders draws a careful line between
# a process EXECUTING a binary and one merely naming it, because "may I swap
# this mount?" must not be blocked by a session's own ttyd bridge carrying
# BINARY=<path> as an argument. That distinction is deliberately NOT applied
# here, and the difference is not an oversight. The question this scan answers
# is "is anything still pointed at this generation?", and a ttyd bridge holding
# BINARY=<gen path> is a true yes — that session resolves its agent through
# that generation. Being conservative costs one retained layer; being clever
# costs a running session its binary.

# aicli_agent_referenced_generations <agent_id> [scan_dir]
# Print every generation id of this agent named by a live process, one per line.
#
# CRITICAL, same as aicli_referenced_generations above: /proc/<pid>/{cmdline,
# environ} are NUL-delimited and must be read through `tr '\0' '\n'` FIRST.
# Running grep straight over them silently returns an INCOMPLETE set under
# process churn — the 2026-08-15 incident that reaped live generations twice.
aicli_agent_referenced_generations() {
    local agent_id="$1" scan_dir="${2:-/proc}"
    [ -n "$agent_id" ] || return 0
    _aicli_proc_text "$scan_dir" \
        | grep -oE "\.versions/${agent_id}/[A-Za-z0-9._@-]+" \
        | sed "s|^\.versions/${agent_id}/||" \
        | sort -u
}

# _aicli_proc_text [scan_dir]
# Every process's cmdline and environ, as newline-delimited text, in ONE pass.
#
# Both halves of the design are load-bearing:
#
#  - tr BEFORE matching. /proc/<pid>/{cmdline,environ} are NUL-delimited, and
#    grep run straight over them treats them as binary and, under process churn,
#    silently returned an INCOMPLETE set — the 2026-08-15 incident that reaped
#    live generations twice. That guarantee is kept exactly: grep only ever sees
#    tr's output.
#
#  - ONE tr for all files, not one per file. The first version spawned a tr and a
#    grep for every file of every process: ~1,470 processes x 2 files, twice per
#    agent, for three agents — about 35,000 process spawns. Measured on
#    192.168.1.4 on 2026-09-15 it held the supervisor's work tick for 39 SECONDS
#    every five minutes, stalling mounts, bakes and upgrade activation, and it
#    failed smoke [49], which gives the supervisor 20. The single pass returns
#    IDENTICAL results (compared per agent) in about 20 ms.
#
# A process that exits mid-scan makes cat report an error for that one file and
# carry on with the next; stderr is suppressed, so it is skipped silently — the
# same outcome the per-file version reached with its -r check. Concatenation
# cannot manufacture a false match across a file boundary: every entry is
# NUL-terminated, so each file's last token ends before the next file begins.
_aicli_proc_text() {
    local scan_dir="${1:-/proc}"
    cat "$scan_dir"/[0-9]*/cmdline "$scan_dir"/[0-9]*/environ 2>/dev/null | tr '\0' '\n'
}

# aicli_agent_has_live_processes <agent_id> [scan_dir]
# True when ANY live process names this agent's install tree — through the
# stable name or through a generation path.
#
# This is the agent-scoped half of the GC safety floor. The plugin's own GC asks
# "are agent wrappers running at all?", which is the right question when there is
# ONE generation stack for the whole plugin. Asked per agent it is the wrong
# question and always true: on any box with a single workspace open, pgrep finds
# a wrapper, and every OTHER agent's superseded generations would then be
# protected forever — each one a live overlay plus a few hundred megabytes of
# writable layer on a flash device.
#
# The floor still has to do its job, so the question becomes agent-scoped: if
# something IS using this agent but the generation scan came back empty, that is
# the untrustworthy scan worth refusing to act on. If nothing is using this agent
# at all, an empty result is simply the truth.
aicli_agent_has_live_processes() {
    local agent_id="$1" scan_dir="${2:-/proc}" hits
    [ -n "$agent_id" ] || return 1
    # One pass through _aicli_proc_text (tr before matching, one process for all
    # files — see its comment for why both matter). Captured into a variable and
    # tested without a pipeline into `grep -q`: under `set -o pipefail` that
    # shape answers backwards (see aicli_generation_is_referenced).
    hits="$(_aicli_proc_text "$scan_dir" | grep -cE "agents/(\.versions/)?${agent_id}/" || true)"
    [ "${hits:-0}" -gt 0 ]
}

# aicli_agent_generation_is_referenced <agent_id> <generation_id> [scan_dir]
# aicli_agent_generation_is_referenced <agent_id> <generation_id> [scan_dir]
#
# No pipeline, for the reason spelled out on aicli_generation_is_referenced
# above: piping the scan into `grep -q` under `set -o pipefail` inverts this
# function's answer, and every caller here runs under pipefail. Getting this
# wrong does not fail loudly — it silently turns side-by-side activation back
# into "tear the old generation down", under a running session.
aicli_agent_generation_is_referenced() {
    local agent_id="$1" gen="$2" scan_dir="${3:-/proc}" refs line
    [ -n "$agent_id" ] && [ -n "$gen" ] || return 1
    refs="$(aicli_agent_referenced_generations "$agent_id" "$scan_dir")"
    while IFS= read -r line; do
        [ "$line" = "$gen" ] && return 0
    done <<< "$refs"
    return 1
}

# aicli_gc_agent_generations <agent_id> <persist_path> [keep_recent] [scan_dir]
#
# Unmount and remove every generation of this agent that is neither the active
# one (the stable symlink's target) nor referenced by a live process, together
# with that generation's own upper/work dirs. Requires resolve_paths.sh to be
# sourced (agent_* helpers) — returns 0 doing nothing if it is not, because a
# GC that cannot resolve its own paths must never guess at them.
#
# Prints a one-line summary. Best effort throughout: a generation that refuses
# to unmount is left exactly as it is and retried on the next sweep, never
# lazy-unmounted (a lazy umount defers releasing the upper, and rm -rf on an
# upper the kernel has not finished with is the copy-up-poison failure WP #1309
# already paid for once).
aicli_gc_agent_generations() {
    local agent_id="$1" persist="$2" keep_recent="${3:-0}" scan_dir="${4:-/proc}"
    [ -n "$agent_id" ] || return 0
    declare -f agent_versions_dir >/dev/null 2>&1 || return 0
    # Mounts first: releasing the bind of an unused plain-directory version is
    # what lets the durable prune below see it as unbound.
    _aicli_gc_agent_mounted_generations "$agent_id" "$persist" "$keep_recent" "$scan_dir"
    # 2026-09-24 (#318): the durable plain-directory versions on flash.
    aicli_gc_passthrough_agent_generations "$agent_id" "$persist" "$scan_dir"
    # 2026-09-26 (#338): the layer files on flash, AFTER the mount release, so
    # a generation just released no longer counts as mounted.
    aicli_gc_agent_layers "$agent_id" "$persist" "$scan_dir"
    return 0
}

# _aicli_gc_agent_mounted_generations — the mount half of
# aicli_gc_agent_generations (the Phase 3 body, unchanged): reap every mounted
# generation under agents/.versions/<id>/ that is neither active nor referenced.
# For a plain-directory agent this releases the bind only; its durable
# directory is decided by aicli_gc_passthrough_agent_generations.
_aicli_gc_agent_mounted_generations() {
    local agent_id="$1" persist="$2" keep_recent="${3:-0}" scan_dir="${4:-/proc}"
    local versions_dir active_gen referenced gen removed=0
    versions_dir="$(agent_versions_dir "$agent_id")"
    [ -d "$versions_dir" ] || return 0

    active_gen="$(agent_live_generation "$agent_id" 2>/dev/null || true)"

    # ONE reliable pass builds the whole referenced set. Per-generation re-scans
    # raced in the plugin's own GC and the same would apply here.
    referenced="$(aicli_agent_referenced_generations "$agent_id" "$scan_dir")"

    # SAFETY FLOOR, mirroring aicli_gc_generations but scoped to THIS agent: an
    # empty referenced set while something is demonstrably using this agent means
    # the scan is untrustworthy, not that nothing is running. Refuse to reap.
    #
    # The scoping is the whole point — see aicli_agent_has_live_processes. A
    # global "are any agents live?" probe is true on any box with one workspace
    # open, which would protect every agent's superseded generations forever.
    if [ -z "$referenced" ] && aicli_agent_has_live_processes "$agent_id" "$scan_dir"; then
        printf 'gc_agent_generations(%s): ABORT — 0 referenced generations found but this agent is in use; refusing to reap\n' \
            "$agent_id" >&2
        return 0
    fi

    while IFS= read -r gen; do
        [ -n "$gen" ] || continue
        # Belt and suspenders on a destructive op: re-assert both invariants
        # here even though the selector already applied them.
        [ "$gen" = "$active_gen" ] && continue
        printf '%s\n' "$referenced" | grep -qxF -- "$gen" && continue
        aicli_reap_agent_generation "$agent_id" "$gen" "$persist" && removed=$((removed + 1))
    done < <(aicli_agent_mounted_generations_for_gc "$agent_id" \
                | aicli_select_reapable_generations "$active_gen" "$keep_recent" "$referenced")

    printf 'gc_agent_generations(%s): reaped %d superseded generation(s); kept active=%s + %d referenced\n' \
        "$agent_id" "$removed" "${active_gen:-none}" "$(printf '%s' "$referenced" | grep -c .)"
}

# ---- Plain-directory agent generations on flash (2026-09-24, #318) ----------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-24 (#317, #318)".
# A plain-directory ("passthrough") agent keeps each version as a durable
# directory, persistence/passthrough/agents/.versions/<id>/<gen>, and the stable
# name persistence/passthrough/agents/<id> is a symlink to the active one.
# Each upgrade adds one directory (100-150 MB); nothing removed them.

# _aicli_pt_gen_stamp <generation_id> <dir>
# The sort key: the UTC stamp in the name (legacy-/initial-/direct-/restore-
# YYYYmmddTHHMMSSZ-<pid>). A directory mtime is not usable: `mv` keeps the old
# one, so an adopted legacy directory looks weeks older than its adoption.
# A name with no stamp falls back to the mtime, in the same format.
_aicli_pt_gen_stamp() {
    local gen="$1" dir="$2" st
    st="$(printf '%s\n' "$gen" | grep -oE '[0-9]{8}T[0-9]{6}Z' | head -1 || true)"
    if [ -z "$st" ]; then
        st="$(date -u -d "@$(stat -c %Y "$dir" 2>/dev/null || echo 0)" +%Y%m%dT%H%M%SZ 2>/dev/null || echo 00000000T000000Z)"
    fi
    printf '%s\n' "$st"
}

# aicli_passthrough_agent_generations <agent_id> <persist>
# Every durable generation of this agent, newest first, one id per line.
aicli_passthrough_agent_generations() {
    local id="$1" persist="$2" versions d gen
    versions="$persist/passthrough/agents/.versions/$id"
    [ -d "$versions" ] || return 0
    for d in "$versions"/*/; do
        [ -d "$d" ] || continue
        d="${d%/}"; gen="$(basename -- "$d")"
        case "$gen" in .*) continue ;; esac
        printf '%s %s\n' "$(_aicli_pt_gen_stamp "$gen" "$d")" "$gen"
    done | sort -r -k1,1 -k2,2 | awk '{print $2}'
}

# aicli_passthrough_agent_bound_generations <agent_id> <persist>
# Every durable generation something is bound to right now, one id per line:
#  - agents/.versions/<id>/<gen> is a mount point (the versioned layout);
#  - the old layout: agents/<id> is a real directory with a bind, and its
#    device and inode are those of a generation directory (a bind shows the
#    same device and inode as its source — the test _pt_mount uses);
#  - the generation the agents/<id> symlink names (even when not mounted yet).
aicli_passthrough_agent_bound_generations() {
    local id="$1" persist="$2" versions stable d gen want have link
    versions="$persist/passthrough/agents/.versions/$id"
    [ -d "$versions" ] || return 0
    declare -f agent_mount >/dev/null 2>&1 || return 0
    stable="$(agent_mount "$id")"
    for d in "$versions"/*/; do
        [ -d "$d" ] || continue
        gen="$(basename -- "${d%/}")"
        case "$gen" in .*) continue ;; esac
        if mountpoint -q "$(agent_versioned_mount "$id" "$gen")" 2>/dev/null; then
            printf '%s\n' "$gen"; continue
        fi
        if [ -d "$stable" ] && [ ! -L "$stable" ] && mountpoint -q "$stable" 2>/dev/null; then
            want="$(stat -L -c '%d:%i' "${d%/}" 2>/dev/null || true)"
            have="$(stat -L -c '%d:%i' "$stable" 2>/dev/null || true)"
            if [ -n "$want" ] && [ "$want" = "$have" ]; then
                printf '%s\n' "$gen"; continue
            fi
        fi
    done
    if [ -L "$stable" ]; then
        link="$(readlink "$stable" 2>/dev/null || true)"
        case "$link" in .versions/"$id"/*) printf '%s\n' "${link#.versions/$id/}" ;; esac
    fi
    return 0
}

# aicli_select_passthrough_prune <active> <previous> <keep_set>
# PURE: from newest-first generation ids on stdin, print the ids to remove —
# everything except the active one, the previous one and the ids in
# <keep_set> (newline-separated: referenced by a process, or bound).
aicli_select_passthrough_prune() {
    local active="$1" previous="$2" keep="$3" gen
    while IFS= read -r gen; do
        [ -n "$gen" ] || continue
        [ "$gen" = "$active" ] && continue
        [ "$gen" = "$previous" ] && continue
        _aicli_in_set "$gen" "$keep" && continue
        printf '%s\n' "$gen"
    done
    return 0
}

# _aicli_in_set <item> <newline-separated set> — exact line match, no pipeline
# (see aicli_generation_is_referenced for why a pipe into grep -q answers
# backwards under pipefail).
_aicli_in_set() {
    local item="$1" set="$2" line
    while IFS= read -r line; do
        [ "$line" = "$item" ] && return 0
    done <<< "$set"
    return 1
}

# aicli_gc_passthrough_agent_generations <agent_id> <persist> [scan_dir]
# Remove the durable plain-directory generations of one agent that the keep
# rule does not protect. Keep: the active one, the previous one, every one a
# live process names, every one with a bind. "In use" is proven exactly as the
# layered GC proves it: the tr-first /proc scan plus the agent-scoped safety
# floor (a scan with no generation while a process names this agent at all is
# untrustworthy -> remove nothing). Prints a one-line summary when the agent
# has plain-directory generations; silent otherwise. Always returns 0.
aicli_gc_passthrough_agent_generations() {
    local id="$1" persist="$2" scan_dir="${3:-/proc}"
    [ -n "$id" ] && [ -n "$persist" ] || return 0
    local versions stable link active previous referenced bound keep gen removed=0 lock_dir lock_id lock_fd
    versions="$persist/passthrough/agents/.versions/$id"
    [ -d "$versions" ] || return 0
    stable="$persist/passthrough/agents/$id"
    # 2026-09-26 (#324): no plain-folder version is in service any more (the
    # stable name is gone, or a link to a folder that is gone): the agent left
    # the plain-folder engine. Its own rule decides — never the keep rule below,
    # which needs an active plain-folder version and so removed nothing forever.
    if [ ! -e "$stable" ]; then
        aicli_gc_retired_passthrough_agent_generations "$id" "$persist" "$scan_dir"
        return 0
    fi
    # The active generation must be KNOWN. A stable name that is not a
    # versioned symlink (missing, or a real directory) means this cannot tell
    # which version is in service: remove nothing.
    if [ ! -L "$stable" ]; then
        printf 'gc_passthrough_generations(%s): stable name is not a version link — nothing removed\n' "$id"
        return 0
    fi
    link="$(readlink "$stable" 2>/dev/null || true)"
    case "$link" in
        .versions/"$id"/*) active="${link#.versions/$id/}" ;;
        *) printf 'gc_passthrough_generations(%s): unexpected stable link %s — nothing removed\n' "$id" "$link"; return 0 ;;
    esac
    if [ -z "$active" ] || [ ! -d "$versions/$active" ]; then
        printf 'gc_passthrough_generations(%s): active generation %s is missing — nothing removed\n' "$id" "$active"
        return 0
    fi

    referenced="$(aicli_agent_referenced_generations "$id" "$scan_dir")"
    if [ -z "$referenced" ] && aicli_agent_has_live_processes "$id" "$scan_dir"; then
        printf 'gc_passthrough_generations(%s): ABORT — 0 referenced generations found but this agent is in use; refusing to remove\n' "$id" >&2
        return 0
    fi
    bound="$(aicli_passthrough_agent_bound_generations "$id" "$persist")"
    keep="$(printf '%s\n%s\n' "$referenced" "$bound" | grep . | sort -u || true)"
    previous="$(aicli_passthrough_agent_generations "$id" "$persist" | grep -vxF -- "$active" | head -1 || true)"

    # The promote (op_promote_passthrough_stage) moves a new directory in and
    # flips the link under this lock. Never decide while one runs.
    lock_dir="${AICLI_LOCK_DIR:-/var/run}"
    lock_id="${id//[^a-zA-Z0-9_-]/_}"
    # A dynamic descriptor: a fixed one (9) could be a lock the caller holds
    # (storagectl's bake lock is fd 9), and closing it here would release it.
    if ! exec {lock_fd}>"$lock_dir/aicli-direct-stage-${lock_id}.lock"; then
        printf 'gc_passthrough_generations(%s): lock unavailable — nothing removed\n' "$id"
        return 0
    fi
    if ! flock -n "$lock_fd"; then
        exec {lock_fd}>&-
        printf 'gc_passthrough_generations(%s): a promote is running — nothing removed\n' "$id"
        return 0
    fi

    while IFS= read -r gen; do
        [ -n "$gen" ] || continue
        # Belt and suspenders on a destructive op: re-check every keep rule.
        case "$gen" in .*|*/*) continue ;; esac
        [ "$gen" = "$active" ] && continue
        [ "$gen" = "$previous" ] && continue
        _aicli_in_set "$gen" "$keep" && continue
        [ "$(readlink "$stable" 2>/dev/null || true)" = ".versions/$id/$gen" ] && continue
        rm -rf "${versions:?}/${gen:?}" 2>/dev/null || true
        if [ ! -e "$versions/$gen" ]; then
            removed=$((removed + 1))
            if declare -f lifecycle_log >/dev/null 2>&1; then
                lifecycle_log "info" "generation" "passthrough_agent_generation_pruned" \
                    "{\"agent\":\"$id\",\"generation\":\"$gen\"}" 2>/dev/null || true
            fi
        fi
    done < <(aicli_passthrough_agent_generations "$id" "$persist" \
                | aicli_select_passthrough_prune "$active" "$previous" "$keep")
    exec {lock_fd}>&-

    printf 'gc_passthrough_generations(%s): removed %d old version(s); kept active=%s previous=%s + %d in use or bound\n' \
        "$id" "$removed" "$active" "${previous:-none}" "$(printf '%s' "$keep" | grep -c . || true)"
    return 0
}

# ---- Plain-folder versions of an agent that left the engine (2026-09-26, #324)
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #324 plain-folder
# versions after an engine switch". The keep rule above needs an active
# plain-folder version. When the agent moves to layers (the global engine
# switch archives the active folder and its stable link), there is none, so
# every other kept version stayed on flash for good.

# _aicli_agent_layered_in_service <agent_id> <persist>
# Sets _LS_STATUS to "ok" when the agent's layered copy is active AND verified:
# the stable name agents/<id> is a link to a layered generation, that
# generation is mounted, its top layer is on disk, every layer of its stack is
# recorded in the manifest, and no install is staged. Otherwise _LS_STATUS says
# why not. Reads only.
_aicli_agent_layered_in_service() {
    local id="$1" persist="$2" names gen top mf stack bn
    _LS_STATUS=""; _LS_GEN=""
    declare -f agent_live_generation >/dev/null 2>&1 || { _LS_STATUS="no_path_helpers"; return 0; }
    names="$(aicli_agent_layer_names "$id" "$persist")"
    [ -n "$names" ] || { _LS_STATUS="no_layers"; return 0; }
    gen="$(agent_live_generation "$id" 2>/dev/null || true)"
    [ -n "$gen" ] || { _LS_STATUS="not_versioned"; return 0; }
    top="$(aicli_agent_generation_top "$gen" 2>/dev/null || true)"
    if [ -z "$top" ] || ! _aicli_in_set "$top" "$names"; then
        _LS_STATUS="active_not_layered"; return 0
    fi
    mountpoint -q "$(agent_versioned_mount "$id" "$gen")" 2>/dev/null || { _LS_STATUS="active_not_mounted"; return 0; }
    if declare -f agent_staging_mount >/dev/null 2>&1 \
       && mountpoint -q "$(agent_staging_mount "$id")" 2>/dev/null; then
        _LS_STATUS="install_staged"; return 0
    fi
    mf="$(_aicli_manifest_agent_layers "$id")" || { _LS_STATUS="manifest_unreadable"; return 0; }
    stack="$(printf '%s\n' "$names" | aicli_layer_stack_of "$top")"
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        _aicli_in_set "$bn" "$mf" || { _LS_STATUS="active_not_in_manifest"; return 0; }
    done <<< "$stack"
    _LS_GEN="$gen"; _LS_STATUS="ok"
    return 0
}

# aicli_gc_retired_passthrough_agent_generations <agent_id> <persist> [scan_dir]
# Remove the plain-folder version folders of an agent that no longer uses the
# plain-folder engine. Remove a folder only when ALL of these hold, else
# remove nothing:
#  - no plain-folder version is in service (the stable name
#    persistence/passthrough/agents/<id> is gone or names a folder that is gone);
#  - the effective engine (when detect_backend.sh is loaded) is not plain-folder;
#  - the layered copy is active and verified (_aicli_agent_layered_in_service);
#  - no install or engine switch holds the agent: the caller does not hold the
#    storage lock (AICLI_AGENT_LAYER_LOCK_HELD), and both per-agent locks — the
#    storage lock (bake, install, engine switch, reconcile) and the promote
#    lock — are free, taken non-blocking; the checks run again under them;
#  - the in-use proof of the #318 clean-up passes: the tr-first /proc scan and
#    the agent-scoped safety floor.
# Then keep every folder a live process names and every folder with a bind
# (agents/.versions/<id>/<gen> is a mount point); remove the rest. rm -rf does
# not follow the captive-state symlinks, so the shared sign-in in agent-state
# stays. Logs each removal. Prints a one-line summary. Always returns 0.
aicli_gc_retired_passthrough_agent_generations() {
    local id="$1" persist="$2" scan_dir="${3:-/proc}"
    [ -n "$id" ] && [ -n "$persist" ] || return 0
    local versions stable referenced bound gen d removed=0 kept=0 lock_id st_fd bk_fd
    versions="$persist/passthrough/agents/.versions/$id"
    stable="$persist/passthrough/agents/$id"
    [ -d "$versions" ] || return 0

    if [ -e "$stable" ]; then
        printf 'gc_retired_passthrough(%s): a plain-folder version is in service — nothing removed\n' "$id"; return 0
    fi
    if [ "${AICLI_AGENT_LAYER_LOCK_HELD:-0}" = "1" ]; then
        printf 'gc_retired_passthrough(%s): an install holds the agent — nothing removed\n' "$id"; return 0
    fi
    if declare -f effective_backend >/dev/null 2>&1 \
       && [ "$(effective_backend agent "$id" "$persist" 2>/dev/null || true)" = "passthrough" ]; then
        printf 'gc_retired_passthrough(%s): the agent is still plain-folder — nothing removed\n' "$id"; return 0
    fi
    _aicli_agent_layered_in_service "$id" "$persist"
    if [ "$_LS_STATUS" != "ok" ]; then
        printf 'gc_retired_passthrough(%s): the new copy is not active and verified (%s) — nothing removed\n' "$id" "$_LS_STATUS"
        return 0
    fi

    # Both per-agent locks, non-blocking. Dynamic descriptors: a fixed one
    # could be a lock the caller holds, and closing it would release that.
    lock_id="${id//[^a-zA-Z0-9_-]/_}"
    if ! exec {bk_fd}>"${AICLI_LOCK_DIR:-/var/run}/aicli-bake-agent-${lock_id}.lock"; then
        printf 'gc_retired_passthrough(%s): lock unavailable — nothing removed\n' "$id"; return 0
    fi
    if ! flock -n "$bk_fd"; then
        exec {bk_fd}>&-
        printf 'gc_retired_passthrough(%s): an install or engine switch holds the agent — nothing removed\n' "$id"; return 0
    fi
    if ! exec {st_fd}>"${AICLI_LOCK_DIR:-/var/run}/aicli-direct-stage-${lock_id}.lock"; then
        exec {bk_fd}>&-
        printf 'gc_retired_passthrough(%s): lock unavailable — nothing removed\n' "$id"; return 0
    fi
    if ! flock -n "$st_fd"; then
        exec {st_fd}>&- {bk_fd}>&-
        printf 'gc_retired_passthrough(%s): a plain-folder promote is running — nothing removed\n' "$id"; return 0
    fi

    # Decide again under the locks: the answer before them is only a hint.
    _aicli_agent_layered_in_service "$id" "$persist"
    if [ -e "$stable" ] || [ "$_LS_STATUS" != "ok" ]; then
        exec {st_fd}>&- {bk_fd}>&-
        printf 'gc_retired_passthrough(%s): changed while taking the locks (%s) — nothing removed\n' "$id" "$_LS_STATUS"
        return 0
    fi
    referenced="$(aicli_agent_referenced_generations "$id" "$scan_dir")"
    if [ -z "$referenced" ] && aicli_agent_has_live_processes "$id" "$scan_dir"; then
        exec {st_fd}>&- {bk_fd}>&-
        printf 'gc_retired_passthrough(%s): ABORT — 0 referenced generations found but this agent is in use; refusing to remove\n' "$id" >&2
        return 0
    fi
    bound="$(aicli_passthrough_agent_bound_generations "$id" "$persist")"

    for d in "$versions"/*/; do
        [ -d "$d" ] || continue
        gen="$(basename -- "${d%/}")"
        case "$gen" in ""|.*|*/*) continue ;; esac
        # Belt and suspenders on a destructive op: every keep rule, per folder.
        if [ "$gen" = "$_LS_GEN" ] || _aicli_in_set "$gen" "$referenced" || _aicli_in_set "$gen" "$bound" \
           || mountpoint -q "$(agent_versioned_mount "$id" "$gen")" 2>/dev/null; then
            kept=$((kept + 1)); continue
        fi
        rm -rf "${versions:?}/${gen:?}" 2>/dev/null || true
        if [ ! -e "$versions/$gen" ]; then
            removed=$((removed + 1))
            if declare -f lifecycle_log >/dev/null 2>&1; then
                lifecycle_log "info" "generation" "passthrough_agent_generation_retired" \
                    "{\"agent\":\"$id\",\"generation\":\"$gen\",\"engine\":\"layering\",\"active\":\"$_LS_GEN\"}" 2>/dev/null || true
            fi
        fi
    done
    # The agent's folder goes too once it is empty, so the sweep stops listing it.
    rmdir "$versions" 2>/dev/null || true
    exec {st_fd}>&- {bk_fd}>&-
    printf 'gc_retired_passthrough(%s): removed %d old plain-folder version(s) after the switch to layers; kept %d in use or bound (active %s)\n' \
        "$id" "$removed" "$kept" "$_LS_GEN"
    return 0
}

# aicli_agent_mounted_generations_for_gc <agent_id>
# Newest-first generation ids to feed the pure selector. Includes generation
# directories with NO overlay bound (a crash between mount and symlink flip
# leaves one behind, and reaping that empty directory is the recovery path the
# spec's "half-mounted version after a crash" failure mode calls for), so this
# deliberately does not reuse agent_mounted_generations, which filters to bound
# mounts only for the callers that are asking "what is running?".
aicli_agent_mounted_generations_for_gc() {
    local id="${1:-}" versions_dir gen_dir
    declare -f agent_versions_dir >/dev/null 2>&1 || return 0
    versions_dir="$(agent_versions_dir "$id")"
    [ -d "$versions_dir" ] || return 0
    for gen_dir in $(ls -1dt "$versions_dir"/*/ 2>/dev/null); do
        gen_dir="${gen_dir%/}"
        case "$(basename -- "$gen_dir")" in .*) continue ;; esac
        basename -- "$gen_dir"
    done
}

# aicli_reap_agent_generation <agent_id> <generation_id> <persist_path>
# Unmount one generation and remove its directory, its upper and its work dir.
# Refuses outright if the generation is the active one. Returns 0 only when the
# generation is really gone.
aicli_reap_agent_generation() {
    local agent_id="$1" gen="$2" persist="$3" gen_dir upper work active
    [ -n "$agent_id" ] && [ -n "$gen" ] || return 1
    declare -f agent_versioned_mount >/dev/null 2>&1 || return 1

    active="$(agent_live_generation "$agent_id" 2>/dev/null || true)"
    if [ -n "$active" ] && [ "$gen" = "$active" ]; then
        printf 'reap_agent_generation(%s/%s): refused — this is the ACTIVE generation\n' "$agent_id" "$gen" >&2
        return 1
    fi

    gen_dir="$(agent_versioned_mount "$agent_id" "$gen")"
    [ -d "$gen_dir" ] || { agent_generation_state_clear "$agent_id" "$gen" 2>/dev/null; return 0; }

    if mountpoint -q "$gen_dir" 2>/dev/null; then
        # REAL umount only — never `umount -l`. A lazy umount returns success
        # while the kernel still holds the upper, and the rm -rf below would
        # then race the kernel over the same tree.
        # `timeout`: this runs on the supervisor's work tick, and a umount that
        # blocks on a wedged filesystem would stall every other check behind it.
        # Still a REAL umount — never `umount -l`, which returns success while
        # the kernel still holds the writable layer the rm below is about to
        # remove (the copy-up poison WP #1309 already paid for once).
        if ! timeout 15 umount "$gen_dir" 2>/dev/null; then
            printf 'reap_agent_generation(%s/%s): still busy — leaving mounted, will retry\n' "$agent_id" "$gen" >&2
            return 1
        fi
    fi

    # The generation's own upper/work, read back from the record written when it
    # was bound. Never guessed: a generation that adopted the pre-Phase-3 shared
    # upper records that path, and reaping it by a derived name would either miss
    # it (leak) or delete the wrong tree.
    upper="$(agent_generation_state_get "$agent_id" "$gen" upper 2>/dev/null || true)"
    work="$(agent_generation_state_get "$agent_id" "$gen" work 2>/dev/null || true)"

    rmdir "$gen_dir" 2>/dev/null || rm -rf "${gen_dir:?}" 2>/dev/null || true

    # Reclaim this generation's writable layer — the single largest cost of an
    # overlap, at 255-450 MB per agent on this hardware.
    #
    # Two shapes reach here. A generation bound by Phase 3 owns a layer keyed
    # "@<gen>", which is unambiguously its own. A generation bound by the Phase 2
    # code has no such key: it uses the id-keyed path from when there was one
    # layer per agent. Refusing to touch the second shape (as this did at first)
    # leaves that layer on flash for good — about 1 GB across this box's agents
    # after their first upgrade each — so it is reclaimed too, behind the only
    # guard that matters: no OTHER generation may record the same path. A
    # generation still mounted on that layer always records it (op_mount
    # back-fills the record for Phase 2 generations precisely so this holds), so
    # the live single-version layer can never be the one removed.
    _reap_layer() {
        local path="$1" label="$2"
        [ -n "$path" ] && [ -d "$path" ] || return 0
        case "$path" in
            *"@$gen") : ;;                                    # unambiguously ours
            *)  if agent_generation_upper_is_shared "$agent_id" "$gen" "$path"; then
                    printf 'reap_agent_generation(%s/%s): %s %s is shared with another generation — keeping it\n' \
                        "$agent_id" "$gen" "$label" "$path" >&2
                    return 0
                fi
                ;;
        esac
        rm -rf "${path:?}" 2>/dev/null || true
    }
    _reap_layer "$upper" "writable layer"
    _reap_layer "$work" "work dir"

    agent_generation_state_clear "$agent_id" "$gen" 2>/dev/null || true
    if declare -f lifecycle_log >/dev/null 2>&1; then
        lifecycle_log "info" "generation" "agent_generation_reaped" \
            "{\"agent\":\"$agent_id\",\"generation\":\"$gen\"}" 2>/dev/null || true
    fi
    return 0
}

# ---- Agent layer retention (2026-09-26, Forgejo #338) ------------------------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent layer
# retention". The generation sweep above releases a superseded generation's
# overlay and writable layer, but never its layers on flash. This part decides
# which layer FILES of a layered agent may go.
#
# The model: a base (agent_<id>_consolidated_*) holds the complete agent tree,
# and a generation whose top layer is T stacks T and each layer below it down to
# and including the newest base at or below T (common.sh _layer_stack_cut is the
# mount's copy of this rule). A staged install writes a fresh base, so the older
# layers stop being part of any new view.
#
# Keep: K1 the active generation's stack; K2 the stack of every generation a
# live process names (the tr-first /proc pass + the agent-scoped floor, exactly
# as the generation sweep proves "in use") and every layer a mounted overlay
# lists in its lowerdir; K3 every layer newer than the active top (baked, not
# yet activated); K4 the previous generation — the stack of the newest layer
# below the active base, skipping the stack a rebase replaced. Delete the rest,
# and only names of the current grammar.
#
# The helpers named aicli_layer_* are PURE (names on stdin, names out) so the
# rule is unit-testable without a filesystem.

_AICLI_GEN_SH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"

# _aicli_layer_strict <agent_id> <basename> — true for a current-grammar name
# of THIS agent: agent_<id>_(delta|consolidated)_<seq10>_<YYYYmmddTHHMMSSZ>.sqsh.
# Only such names are ever deleted; an older grammar is kept, never guessed at.
_aicli_layer_strict() {
    local id="$1" bn="$2" rest
    case "$bn" in "agent_${id}_"*.sqsh) : ;; *) return 1 ;; esac
    rest="${bn#agent_"${id}"_}"
    [[ "$rest" =~ ^(delta|consolidated)_[0-9]{10}_[0-9]{8}T[0-9]{6}Z\.sqsh$ ]]
}

# aicli_agent_layer_names <agent_id> <persist> — every layer file of the agent,
# newest first, as basenames. Current-grammar names sort by seq then time
# stamp (the common.sh _layer_sort_key rule); any other name sorts below them.
aicli_agent_layer_names() {
    local id="$1" persist="$2" f bn rest key
    [ -n "$id" ] && [ -d "$persist" ] || return 0
    for f in "$persist"/agent_"${id}"_*.sqsh; do
        [ -f "$f" ] || continue
        bn="$(basename -- "$f")"
        if _aicli_layer_strict "$id" "$bn"; then
            rest="${bn#agent_"${id}"_}"; rest="${rest%.sqsh}"
            key="${rest#*_}"                 # <seq10>_<dt>
        else
            key="0000000000_${bn}"
        fi
        printf '%s\t%s\n' "$key" "$bn"
    done | LC_ALL=C sort -r | cut -f2-
}

# aicli_layer_is_base <basename> — a base holds the complete agent tree.
aicli_layer_is_base() {
    case "${1:-}" in *_consolidated_*) return 0 ;; *) return 1 ;; esac
}

# aicli_layer_stack_of <top> — PURE: from newest-first names on stdin, print the
# stack of the generation whose top layer is <top>: <top> and each older layer
# down to and including the newest base. Nothing when <top> is not listed.
aicli_layer_stack_of() {
    local top="$1" bn seen=0
    [ -n "$top" ] || return 0
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        if [ "$seen" = 0 ]; then
            [ "$bn" = "$top" ] || continue
            seen=1
        fi
        printf '%s\n' "$bn"
        aicli_layer_is_base "$bn" && return 0
    done
    return 0
}

# aicli_layer_newer_than <top> — PURE: every name sorting above <top>. When
# <top> is not listed, nothing (the caller then refuses to act anyway).
aicli_layer_newer_than() {
    local top="$1" bn out=""
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        if [ "$bn" = "$top" ]; then printf '%s' "$out"; return 0; fi
        out="$out$bn"$'\n'
    done
    return 0
}

# aicli_layer_previous_top <base> <skip_set> — PURE: the newest name below
# <base> that is not in <skip_set> (the layers a rebase replaced). That is the
# top of the previous generation (K4).
aicli_layer_previous_top() {
    local base="$1" skip="$2" bn below=0
    [ -n "$base" ] || return 0
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        if [ "$below" = 0 ]; then
            [ "$bn" = "$base" ] && below=1
            continue
        fi
        _aicli_in_set "$bn" "$skip" && continue
        printf '%s\n' "$bn"
        return 0
    done
    return 0
}

# aicli_select_agent_layer_prune <agent_id> <keep_set> — PURE: from newest-first
# names on stdin, print the names to delete: every current-grammar name of this
# agent that is not in <keep_set>.
aicli_select_agent_layer_prune() {
    local id="$1" keep="$2" bn
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        _aicli_layer_strict "$id" "$bn" || continue
        _aicli_in_set "$bn" "$keep" && continue
        printf '%s\n' "$bn"
    done
    return 0
}

# aicli_agent_generation_top <generation_id> — the top layer of a layered
# generation: its id is "<layer stem>-<hash>" (aicli_agent_generation_id).
aicli_agent_generation_top() {
    local gen="${1:-}"
    case "$gen" in agent_*-[0-9a-f]*) printf '%s.sqsh\n' "${gen%-*}" ;; *) return 1 ;; esac
}

# _aicli_layer_mnt_base — where op_mount loop-mounts each layer.
_aicli_layer_mnt_base() { printf '%s\n' "${AICLI_LAYER_MNT_BASE:-/tmp/unraid-aicliagents/mnt}"; }

# aicli_agent_layers_in_overlays <agent_id> — every layer of this agent that a
# mounted overlay lists in its lowerdir (the kernel's own "in use" answer), one
# basename per line. Reads AICLI_PROC_MOUNTS (tests) or /proc/mounts.
aicli_agent_layers_in_overlays() {
    local id="$1" mtab base _dev _mnt fstype opts _rest lower entry stem
    mtab="${AICLI_PROC_MOUNTS:-/proc/mounts}"
    base="$(_aicli_layer_mnt_base)"
    [ -r "$mtab" ] || return 0
    while read -r _dev _mnt fstype opts _rest; do
        [ "$fstype" = "overlay" ] || continue
        case ",$opts," in *",lowerdir="*) : ;; *) continue ;; esac
        lower="${opts#*lowerdir=}"; lower="${lower%%,*}"
        while IFS= read -r entry; do
            case "$entry" in "$base"/agent_"${id}"_*) : ;; *) continue ;; esac
            stem="${entry#"$base"/}"
            printf '%s.sqsh\n' "$stem"
        done <<< "${lower//:/$'\n'}"
    done < "$mtab"
    return 0
}

# _aicli_layer_loop_mounted <stem> — true when the layer's loop mount is up.
_aicli_layer_loop_mounted() {
    local target mtab
    target="$(_aicli_layer_mnt_base)/$1"
    mtab="${AICLI_PROC_MOUNTS:-/proc/mounts}"
    [ -r "$mtab" ] || return 1
    awk -v t="$target" '$2 == t { f = 1 } END { exit f ? 0 : 1 }' "$mtab"
}

# _aicli_layer_umount <dir> — REAL umount of a layer's loop mount, never lazy
# (a lazy umount returns while the kernel still reads the file). Bounded, as in
# aicli_reap_agent_generation: this runs on the supervisor's work tick.
_aicli_layer_umount() {
    timeout 15 umount "$1" 2>/dev/null
}

# _aicli_manifest_agent_layers <agent_id> — the layer names the manifest
# records for agent/<id>, one per line. Returns 1 when it cannot be read: the
# caller then deletes nothing.
_aicli_manifest_agent_layers() {
    local id="$1" mpath raw
    command -v php >/dev/null 2>&1 || return 1
    if declare -f manifest_path >/dev/null 2>&1; then
        mpath="$(manifest_path 2>/dev/null)"
    else
        mpath="${AICLI_MANIFEST_PATH:-/boot/config/plugins/unraid-aicliagents/layer_manifest.json}"
    fi
    [ -f "$mpath" ] || return 1
    if declare -f manifest_read_locked >/dev/null 2>&1; then
        raw="$(manifest_read_locked "$mpath" 2>/dev/null)" || return 1
    else
        raw="$(cat "$mpath" 2>/dev/null)" || return 1
    fi
    printf '%s' "$raw" | php -d display_errors=0 -r '
        $m = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($m)) exit(1);
        $e = $m["entities"][$argv[1]] ?? [];
        foreach ((array)($e["expected_layers"] ?? []) as $l) {
            $f = (string)($l["filename"] ?? "");
            if ($f !== "") echo $f, "\n";
        }
    ' "agent/$id"
}

# _aicli_rebase_intent_layers <persist> <agent_id> [keep] — the layers a rebase
# replaced, from its write-ahead intent. With <keep>, only when the intent
# keeps exactly that base.
_aicli_rebase_intent_layers() {
    local persist="$1" id="$2" keep="${3:-}" f json seg
    f="${persist%/}/.aicli-intent-agent-${id}.json"
    [ -f "$f" ] || return 0
    json="$(cat "$f" 2>/dev/null)" || return 0
    case "$json" in *'"op":"agent_rebase"'*) : ;; *) return 0 ;; esac
    if [ -n "$keep" ]; then
        case "$json" in *"\"keep\":\"$keep\""*) : ;; *) return 0 ;; esac
    fi
    case "$json" in *'"delete":['*) : ;; *) return 0 ;; esac
    seg="${json#*\"delete\":[}"; seg="${seg%%]*}"
    printf '%s\n' "$seg" | grep -oE '"[^"]+"' | tr -d '"' || true
}

# _aicli_rebase_intent_clear_if_done <persist> <agent_id> — remove a rebase's
# write-ahead intent once every layer it replaced is gone from disk.
_aicli_rebase_intent_clear_if_done() {
    local persist="$1" id="$2" f bn
    f="${persist%/}/.aicli-intent-agent-${id}.json"
    [ -f "$f" ] || return 0
    case "$(cat "$f" 2>/dev/null)" in *'"op":"agent_rebase"'*) : ;; *) return 0 ;; esac
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        [ -e "$persist/$bn" ] && return 0
    done <<< "$(_aicli_rebase_intent_layers "$persist" "$id")"
    rm -f "$f" 2>/dev/null || true
    return 0
}

# _aicli_agent_layer_plan <agent_id> <persist> <scan_dir>
# Decide. Sets _LP_STATUS ("ok" or why nothing may be deleted), _LP_ACTIVE (the
# active top layer), _LP_KEEP and _LP_DELETE (newline-separated basenames).
# Deletes nothing.
_aicli_agent_layer_plan() {
    local id="$1" persist="$2" scan_dir="${3:-/proc}"
    local names gen top base referenced ref rtop kernel skip prev keep
    _LP_STATUS=""; _LP_ACTIVE=""; _LP_KEEP=""; _LP_DELETE=""
    names="$(aicli_agent_layer_names "$id" "$persist")"
    [ -n "$names" ] || { _LP_STATUS="no_layers"; return 0; }
    declare -f agent_live_generation >/dev/null 2>&1 || { _LP_STATUS="no_path_helpers"; return 0; }
    gen="$(agent_live_generation "$id" 2>/dev/null || true)"
    [ -n "$gen" ] || { _LP_STATUS="not_versioned"; return 0; }
    top="$(aicli_agent_generation_top "$gen" 2>/dev/null || true)"
    if [ -z "$top" ] || ! _aicli_in_set "$top" "$names"; then
        _LP_STATUS="active_not_layered"; return 0
    fi
    _LP_ACTIVE="$top"
    mountpoint -q "$(agent_versioned_mount "$id" "$gen")" 2>/dev/null || { _LP_STATUS="active_not_mounted"; return 0; }
    if declare -f agent_staging_mount >/dev/null 2>&1 \
       && mountpoint -q "$(agent_staging_mount "$id")" 2>/dev/null; then
        _LP_STATUS="install_staged"; return 0
    fi

    referenced="$(aicli_agent_referenced_generations "$id" "$scan_dir")"
    if [ -z "$referenced" ] && aicli_agent_has_live_processes "$id" "$scan_dir"; then
        _LP_STATUS="scan_untrustworthy"; return 0
    fi

    # K1 (active) and K3 (baked, not yet active)
    keep="$(printf '%s\n' "$names" | aicli_layer_stack_of "$top")"$'\n'
    keep="$keep$(printf '%s\n' "$names" | aicli_layer_newer_than "$top")"$'\n'
    # K2: named by a live process
    while IFS= read -r ref; do
        [ -n "$ref" ] || continue
        rtop="$(aicli_agent_generation_top "$ref" 2>/dev/null || true)"
        [ -n "$rtop" ] || continue
        keep="$keep$(printf '%s\n' "$names" | aicli_layer_stack_of "$rtop")"$'\n'
    done <<< "$referenced"
    # K2: listed in a mounted overlay's lowerdir
    kernel="$(aicli_agent_layers_in_overlays "$id")"
    keep="$keep$kernel"$'\n'
    # K4: the previous generation, skipping what a rebase of this base replaced
    base="$(printf '%s\n' "$names" | aicli_layer_stack_of "$top" | tail -1)"
    skip="$(_aicli_rebase_intent_layers "$persist" "$id" "$base")"
    prev="$(printf '%s\n' "$names" | aicli_layer_previous_top "$base" "$skip")"
    [ -n "$prev" ] && keep="$keep$(printf '%s\n' "$names" | aicli_layer_stack_of "$prev")"$'\n'

    _LP_KEEP="$(printf '%s\n' "$keep" | grep . | sort -u || true)"
    _LP_DELETE="$(printf '%s\n' "$names" | aicli_select_agent_layer_prune "$id" "$_LP_KEEP")"
    _LP_STATUS="ok"
    return 0
}

# aicli_gc_agent_layers <agent_id> <persist> [scan_dir]
# Delete the layer files of one layered agent that the retention rule does not
# keep. Preconditions (else nothing is deleted): layered versioned layout, the
# active generation mounted and its top layer on disk, every layer of the active
# stack recorded in the manifest, no install staged, the storage lock free
# (non-blocking) or held by the caller (AICLI_AGENT_LAYER_LOCK_HELD=1), and the
# scan floor. Per layer: release its loop mount (real umount; busy -> keep),
# remove it from the manifest, delete the file. Prints a one-line summary when
# the agent has layers. Always returns 0.
aicli_gc_agent_layers() {
    local id="$1" persist="$2" scan_dir="${3:-/proc}"
    [ -n "$id" ] && [ -n "$persist" ] || return 0
    local lock_fd="" lock_id bn stem removed=0 kept_busy=0 failed=0 mf active_stack
    _aicli_agent_layer_plan "$id" "$persist" "$scan_dir"
    [ "$_LP_STATUS" = "no_layers" ] && return 0
    if [ "$_LP_STATUS" != "ok" ]; then
        printf 'gc_agent_layers(%s): nothing removed (%s)\n' "$id" "$_LP_STATUS"
        return 0
    fi
    if [ -z "$_LP_DELETE" ]; then
        _aicli_rebase_intent_clear_if_done "$persist" "$id"
        printf 'gc_agent_layers(%s): nothing to remove; kept %d layer(s)\n' "$id" "$(printf '%s' "$_LP_KEEP" | grep -c . || true)"
        return 0
    fi

    # The storage lock: a bake, consolidate, restore and the reconcile all take
    # it, so no layer is written, adopted or checked while this decides.
    if [ "${AICLI_AGENT_LAYER_LOCK_HELD:-0}" != "1" ]; then
        lock_id="${id//[^a-zA-Z0-9_-]/_}"
        if ! exec {lock_fd}>"${AICLI_LOCK_DIR:-/var/run}/aicli-bake-agent-${lock_id}.lock"; then
            printf 'gc_agent_layers(%s): nothing removed (lock_unavailable)\n' "$id"
            return 0
        fi
        if ! flock -n "$lock_fd"; then
            exec {lock_fd}>&-
            printf 'gc_agent_layers(%s): nothing removed (storage_lock_held)\n' "$id"
            return 0
        fi
        # Decide again under the lock: the answer before it is only a hint.
        _aicli_agent_layer_plan "$id" "$persist" "$scan_dir"
        if [ "$_LP_STATUS" != "ok" ] || [ -z "$_LP_DELETE" ]; then
            exec {lock_fd}>&-
            printf 'gc_agent_layers(%s): nothing removed (%s)\n' "$id" "${_LP_STATUS:-changed}"
            return 0
        fi
    fi

    # The replacement must be verified and recorded before anything goes:
    # every layer of the active stack is in the manifest.
    if ! declare -f manifest_remove_layer >/dev/null 2>&1; then
        # shellcheck source=/dev/null
        source "$_AICLI_GEN_SH_DIR/../storage/manifest_write.sh" 2>/dev/null || true
    fi
    if ! declare -f manifest_remove_layer >/dev/null 2>&1 || ! mf="$(_aicli_manifest_agent_layers "$id")"; then
        [ -n "$lock_fd" ] && exec {lock_fd}>&-
        printf 'gc_agent_layers(%s): nothing removed (manifest_unreadable)\n' "$id"
        return 0
    fi
    active_stack="$(aicli_agent_layer_names "$id" "$persist" | aicli_layer_stack_of "$_LP_ACTIVE")"
    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        if ! _aicli_in_set "$bn" "$mf"; then
            [ -n "$lock_fd" ] && exec {lock_fd}>&-
            printf 'gc_agent_layers(%s): nothing removed (active layer %s is not in the manifest)\n' "$id" "$bn"
            return 0
        fi
    done <<< "$active_stack"

    while IFS= read -r bn; do
        [ -n "$bn" ] || continue
        # Belt and suspenders on a destructive op: the grammar and the keep set.
        _aicli_layer_strict "$id" "$bn" || continue
        _aicli_in_set "$bn" "$_LP_KEEP" && continue
        stem="${bn%.sqsh}"
        # 1. The loop mount. No overlay lists this layer (K2), so a busy loop
        #    mount means something holds the layer directly: keep it.
        if _aicli_layer_loop_mounted "$stem"; then
            if ! _aicli_layer_umount "$(_aicli_layer_mnt_base)/$stem"; then
                kept_busy=$((kept_busy + 1)); continue
            fi
            rmdir "$(_aicli_layer_mnt_base)/$stem" 2>/dev/null || true
        fi
        # 2. The manifest, BEFORE the file: a crash may leave an untracked file
        #    (reconcile adopts it; the next sweep deletes it), never a manifest
        #    entry without its file (reconcile would halt the agent).
        if ! manifest_remove_layer agent "$id" "$bn"; then
            failed=$((failed + 1)); continue
        fi
        # 3. The file.
        rm -f "${persist:?}/${bn:?}" 2>/dev/null || true
        if [ -e "$persist/$bn" ]; then
            failed=$((failed + 1)); continue
        fi
        removed=$((removed + 1))
        if declare -f lifecycle_log >/dev/null 2>&1; then
            lifecycle_log "info" "generation" "agent_layer_pruned" \
                "{\"agent\":\"$id\",\"layer\":\"$bn\",\"active\":\"$_LP_ACTIVE\"}" 2>/dev/null || true
        fi
    done <<< "$_LP_DELETE"

    _aicli_rebase_intent_clear_if_done "$persist" "$id"
    [ -n "$lock_fd" ] && exec {lock_fd}>&-
    printf 'gc_agent_layers(%s): removed %d layer(s); kept %d (active top %s); %d busy, %d failed\n' \
        "$id" "$removed" "$(printf '%s' "$_LP_KEEP" | grep -c . || true)" "$_LP_ACTIVE" "$kept_busy" "$failed"
    return 0
}

# aicli_agent_rebase_wanted <agent_id> <persist> [scan_dir]
# True (0) when a one-time rebase of this agent's long stack is due: layered
# versioned layout; the active generation mounted; its stack has 2+ layers; no
# layer newer than its top; no install staged; no process names the agent; the
# active generation's writable layer is empty (nothing unbaked); no failed
# rebase in the last 6 hours. Prints the reason when it is not due.
aicli_agent_rebase_wanted() {
    local id="$1" persist="$2" scan_dir="${3:-/proc}" names gen top stack upper marker
    names="$(aicli_agent_layer_names "$id" "$persist")"
    [ -n "$names" ] || { echo "no_layers"; return 1; }
    declare -f agent_live_generation >/dev/null 2>&1 || { echo "no_path_helpers"; return 1; }
    gen="$(agent_live_generation "$id" 2>/dev/null || true)"
    top="$(aicli_agent_generation_top "$gen" 2>/dev/null || true)"
    if [ -z "$top" ] || ! _aicli_in_set "$top" "$names"; then echo "active_not_layered"; return 1; fi
    [ "$(printf '%s\n' "$names" | head -1)" = "$top" ] || { echo "newer_layer_waits"; return 1; }
    stack="$(printf '%s\n' "$names" | aicli_layer_stack_of "$top" | grep -c . || true)"
    [ "${stack:-0}" -ge 2 ] || { echo "single_layer"; return 1; }
    mountpoint -q "$(agent_versioned_mount "$id" "$gen")" 2>/dev/null || { echo "active_not_mounted"; return 1; }
    if declare -f agent_staging_mount >/dev/null 2>&1 \
       && mountpoint -q "$(agent_staging_mount "$id")" 2>/dev/null; then
        echo "install_staged"; return 1
    fi
    if aicli_agent_has_live_processes "$id" "$scan_dir"; then echo "in_use"; return 1; fi
    upper="$(agent_generation_state_get "$id" "$gen" upper 2>/dev/null || true)"
    [ -n "$upper" ] || { echo "writable_layer_unknown"; return 1; }
    if [ -d "$upper" ] && [ -n "$(find "$upper" -mindepth 1 -print -quit 2>/dev/null)" ]; then
        echo "writable_layer_not_empty"; return 1
    fi
    marker="${AICLI_REBASE_MARKER_DIR:-/tmp/unraid-aicliagents}/.agent_rebase_failed_${id}"
    if [ -f "$marker" ] && [ -n "$(find "$marker" -mmin -360 2>/dev/null)" ]; then
        echo "failed_recently"; return 1
    fi
    return 0
}
