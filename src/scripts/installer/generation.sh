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
    local deploy_payload="{\"event\":\"activated\",\"generation\":\"$generation_id\"}"
    curl -s -X POST --unix-socket /var/run/nginx.socket \
        "http://localhost/pub/aicli_deploy?buffer_length=1" \
        -H "Content-Type: application/json" \
        -d "$deploy_payload" \
        --max-time 2 >/dev/null 2>&1 || true
    # PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md 1a.2: the same tee NchanService::publish()
    # does for a PHP publisher, for this shell one. Best-effort — never blocks activation.
    php "$emhttp_dest/src/scripts/event-append.php" --kind=deploy.activated --actor=system \
        --summary="generation active: $generation_id" --subject="{\"generation\":\"$generation_id\"}" \
        --data="$deploy_payload" >/dev/null 2>&1 || true

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
aicli_referenced_generations() {
    local scan_dir="${1:-/proc}" f g
    for f in "$scan_dir"/[0-9]*/cmdline "$scan_dir"/[0-9]*/environ; do
        [ -r "$f" ] || continue
        # Group-redirect stderr so a proc that vanishes AFTER the -r check (a race during
        # an install's process churn) is silently skipped, not logged. `< "$f"`'s own
        # failure is reported to the group's stderr, so it must be suppressed here.
        { tr '\0' '\n' < "$f"; } 2>/dev/null \
            | grep -oE '\.generations/[0-9]+\.[0-9.]+-[a-f0-9]+' \
            | sed 's|^\.generations/||'
    done | sort -u
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
