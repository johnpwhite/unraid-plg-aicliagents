#!/bin/bash
# atomic_write_layer.sh — Atomic SquashFS layer writer for AICliAgents.
#
# Usage (source this file, then call the function):
#   source atomic_write_layer.sh
#   FINAL_BASENAME=$(atomic_write_layer <type> <id> <persist_path> <upper_dir> <kind>)
#
# Arguments:
#   type         — "home" or "agent"
#   id           — entity identifier (e.g. "root", "claude-code")
#   persist_path — absolute path to the persistence directory (on flash)
#   upper_dir    — directory to bake (ZRAM upper for delta, mounted stack for consolidated)
#   kind         — "delta" or "consolidated"
#
# Naming convention (canonical, per STORAGE_DURABILITY_SUPERVISOR.md):
#   ${type}_${id}_${kind}_${seq10}_${dt}.sqsh
#   where kind ∈ {delta, consolidated}
#         seq10 = 10-digit zero-padded monotonic sequence (e.g. 0000000012)
#         ${dt} = $(date -u +%Y%m%dT%H%M%SZ) — UTC, ISO 8601 basic, 16 chars.
# Legacy formats (no seq10 segment) remain valid lower layers but are not produced.
# Legacy formats remain valid lower layers but are not produced by this writer.
#
# Returns 0 on success and prints the final basename to stdout.
# Returns non-zero on any failure; prints nothing to stdout.
# All progress/diagnostic output goes to stderr.
#
# Environment:
#   MKSQUASHFS_ARGS — override mksquashfs flags (default: xz, x86 BCJ, 1M block)
#   AICLI_BAKE_MANIFEST_OUT — if set, write the list of files captured in the
#     verified layer (one relative path per line) to this file. WP #1277: lets
#     op_bake confine the post-bake reclaim to proven-baked files.
#
# Lifecycle log events emitted:
#   atomic_write_start, atomic_write_verify_failed, atomic_write_ok, atomic_write_failed

# Source canonical path resolver (for lifecycle_log) — tolerate missing.
# Only source if not already sourced (idempotent).
if ! declare -f lifecycle_log >/dev/null 2>&1; then
    _AWLSH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"
    source "${_AWLSH_DIR}/resolve_paths.sh" 2>/dev/null || true
fi
# Layer-identity helpers (_layer_next_seq) live in common.sh. Callers
# (commit_stack/consolidate) source it first, but guard for standalone use.
if ! declare -f _layer_next_seq >/dev/null 2>&1; then
    _AWLSH_DIR="${_AWLSH_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)}"
    source "${_AWLSH_DIR}/common.sh" 2>/dev/null || true
fi

# Default mksquashfs flags — same as commit_stack.sh and consolidate_layers.sh.
# Override via MKSQUASHFS_ARGS env var.
_AWL_DEFAULT_ARGS="-comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend"

# atomic_write_layer <type> <id> <persist_path> <upper_dir> <kind>
atomic_write_layer() {
    local type="${1:-}"
    local id="${2:-}"
    local persist_path="${3:-}"
    local upper_dir="${4:-}"
    local kind="${5:-delta}"

    # ----- Validate arguments ---------------------------------------------------
    if [ -z "$type" ] || [ -z "$id" ] || [ -z "$persist_path" ] || [ -z "$upper_dir" ]; then
        echo "[atomic_write_layer] ERROR: missing required arguments" >&2
        return 1
    fi
    if [ "$kind" != "delta" ] && [ "$kind" != "consolidated" ]; then
        echo "[atomic_write_layer] ERROR: kind must be 'delta' or 'consolidated', got: $kind" >&2
        return 1
    fi
    if [ ! -d "$upper_dir" ]; then
        echo "[atomic_write_layer] ERROR: upper_dir does not exist: $upper_dir" >&2
        return 1
    fi
    if [ ! -d "$persist_path" ]; then
        echo "[atomic_write_layer] ERROR: persist_path does not exist: $persist_path" >&2
        return 1
    fi

    # ----- Step 1: Compute target filename --------------------------------------
    # dt: UTC ISO 8601 basic (YYYYMMDDTHHMMSSZ) — 16 chars, human-readable.
    local dt
    dt=$(date -u +%Y%m%dT%H%M%SZ)
    local epoch
    epoch=$(date +%s)

    # WP D01/D02/D03: a monotonic per-entity seq is the PRIMARY layer identity +
    # sort key. It makes the name unique even within one UTC second (D01) and makes
    # ordering immune to wall-clock step-back (D02); legacy layers parse as seq 0 so
    # the first seq>=1 layer correctly sorts above them (D03). dt is retained as the
    # human-readable secondary tiebreak. _layer_next_seq scans this entity's existing
    # layers (delta + consolidated) for the max seq and returns max+1.
    local seq seq10
    if declare -f _layer_next_seq >/dev/null 2>&1; then
        seq="$(_layer_next_seq "$persist_path" "$type" "$id")"
    else
        seq=1   # helper unavailable (should not happen) — safe non-colliding default path below
    fi
    seq10=$(printf '%010d' "$seq" 2>/dev/null || printf '%010d' 1)

    local final_name="${type}_${id}_${kind}_${seq10}_${dt}.sqsh"

    local final_path="${persist_path}/${final_name}"

    # Tempfile: sibling of final in the SAME filesystem (same dir = atomic rename).
    # Pattern: .<finalname>.tmp.<pid>.<epoch>  — matches neither *_*.sqsh nor *.sqsh globs
    # used by mount_stack.sh discovery. Epoch suffix keeps tempfiles unique per writer.
    local pid=$$
    local tmp_path="${persist_path}/.${final_name}.tmp.${pid}.${epoch}"

    # ----- Lifecycle log: start -------------------------------------------------
    lifecycle_log "info" "atomic_write_layer" "atomic_write_start" \
        "{\"type\":\"$type\",\"id\":\"$id\",\"kind\":\"$kind\",\"final\":\"$final_name\"}" 2>/dev/null || true

    echo "[atomic_write_layer] Writing $kind layer: $final_name (via tempfile)" >&2

    # Resolve + log the batch deprioritization plan once per bake (best-effort).
    if declare -f _batch_log_line >/dev/null 2>&1; then
        _batch_log_line || true
    fi

    # ----- Cleanup trap: unlink tempfile on any exit from this function ---------
    # We use a subshell so the trap doesn't escape to the caller.
    # The subshell also gives us a clean 'set -euo pipefail' scope.
    local result_name
    result_name=$(
        set -euo pipefail

        _awl_cleanup() {
            rm -f "$tmp_path" 2>/dev/null || true
        }
        trap '_awl_cleanup' EXIT

        # ----- Step 2: mksquashfs to tempfile -----------------------------------
        local mksq_args="${MKSQUASHFS_ARGS:-$_AWL_DEFAULT_ARGS}"
        echo "[atomic_write_layer] Running mksquashfs..." >&2

        # BATCH_BAKE_DEPRIORITIZE: prepend nice/ionice/taskset (if available) and
        # inject a capped -processors. With AICLI_BATCH_DISABLE=1 (or the helper
        # absent) the prefix is empty and args are unchanged — byte-identical to
        # legacy behavior. Heavy I/O steps below get the same prefix.
        local _batch_pfx=""
        if declare -f _batch_prefix >/dev/null 2>&1; then
            _batch_pfx="$(_batch_prefix)"
            mksq_args="$(_batch_mksquashfs_args "$mksq_args")"
        fi

        # shellcheck disable=SC2086
        if ! $_batch_pfx mksquashfs "$upper_dir" "$tmp_path" $mksq_args > /dev/null 2>&1; then
            echo "[atomic_write_layer] ERROR: mksquashfs failed" >&2
            exit 1
        fi

        # ----- Step 3: fsync the tempfile (full sync — safe on all filesystems) -
        echo "[atomic_write_layer] Syncing to disk..." >&2
        # shellcheck disable=SC2086
        $_batch_pfx sync

        # ----- Step 4: Compute sha256 ------------------------------------------
        local sha256
        # shellcheck disable=SC2086
        sha256=$($_batch_pfx sha256sum "$tmp_path" 2>/dev/null | awk '{print $1}')
        if [ -z "$sha256" ]; then
            echo "[atomic_write_layer] ERROR: sha256sum failed on tempfile" >&2
            exit 1
        fi
        local byte_count
        byte_count=$(stat -c '%s' "$tmp_path" 2>/dev/null || echo 0)

        echo "[atomic_write_layer] sha256=$sha256 bytes=$byte_count" >&2

        # ----- Step 5: Verify readability via RO mount on scratch mountpoint ----
        local scratch_mnt="/tmp/unraid-aicliagents/scratch/atomic-verify-${pid}-${epoch}"
        mkdir -p "$scratch_mnt" 2>/dev/null || {
            echo "[atomic_write_layer] ERROR: cannot create scratch mount dir: $scratch_mnt" >&2
            exit 1
        }

        local verify_ok=1
        local mounted=0

        if mount -o loop,ro "$tmp_path" "$scratch_mnt" 2>/dev/null; then
            mounted=1
            # Sample up to 50 files for readability — if zero files, that's OK (empty squashfs)
            local bad_reads=0
            while IFS= read -r f; do
                if ! head -c 1 "$f" >/dev/null 2>&1; then
                    bad_reads=$((bad_reads + 1))
                    echo "[atomic_write_layer] WARNING: unreadable file in squashfs: $f" >&2
                fi
            done < <(find "$scratch_mnt" -type f 2>/dev/null | head -50)

            if [ "$bad_reads" -gt 0 ]; then
                verify_ok=0
                echo "[atomic_write_layer] ERROR: $bad_reads file(s) failed readability check" >&2
            else
                echo "[atomic_write_layer] Verify: readability check passed" >&2
            fi

            # WP #1277 (bake-confirmed reclaim): if the caller asked for a baked-
            # file manifest, emit the FULL list of regular files actually present
            # in the verified layer, relative to the bake root. This is the
            # authoritative "what got captured" set — op_bake maps these onto
            # UPPER_DIR and passes them to selective_upper_cleanup so the post-bake
            # reclaim only wipes files PROVEN to be in this layer. Written while the
            # layer is still RO-mounted; only on a passing verify (a corrupt layer
            # must never authorise a wipe). Relative paths are derived by stripping
            # the scratch mountpoint prefix (portable — no find -printf dependency).
            if [ "$verify_ok" -eq 1 ] && [ -n "${AICLI_BAKE_MANIFEST_OUT:-}" ]; then
                find "$scratch_mnt" -type f 2>/dev/null | while IFS= read -r _bf; do
                    printf '%s\n' "${_bf#"$scratch_mnt"/}"
                done > "$AICLI_BAKE_MANIFEST_OUT" 2>/dev/null || true
            fi

            umount -l "$scratch_mnt" 2>/dev/null || umount "$scratch_mnt" 2>/dev/null || true
        else
            # Mount failed — squashfs is corrupt or empty-but-mountable depends on kernel.
            # If mksquashfs wrote 0 files (truly empty upper), the sqsh will mount OK.
            # A mount failure here indicates a real problem.
            echo "[atomic_write_layer] ERROR: cannot mount tempfile for verification" >&2
            verify_ok=0
        fi

        # Always clean up scratch dir (best-effort)
        rmdir "$scratch_mnt" 2>/dev/null || true

        if [ "$verify_ok" -eq 0 ]; then
            # lifecycle_log for verify failure — emitted before exit so trap fires after
            lifecycle_log "error" "atomic_write_layer" "atomic_write_verify_failed" \
                "{\"type\":\"$type\",\"id\":\"$id\",\"kind\":\"$kind\",\"final\":\"$final_name\",\"sha256\":\"$sha256\"}" 2>/dev/null || true
            exit 1
        fi

        # ----- Step 6: Atomic rename to final path ------------------------------
        # Collision guard: refuse to overwrite an existing layer at the target name.
        # In normal operation this never fires (per-entity lock + 1-sec dt resolution).
        # If it does, NTP rolled the clock back or an operator manually re-baked.
        if [ -e "$final_path" ]; then
            echo "[atomic_write_layer] ERROR: target exists, refusing to overwrite: $final_path" >&2
            lifecycle_log "critical" "atomic_write_layer" "atomic_write_collision" \
                "{\"type\":\"$type\",\"id\":\"$id\",\"kind\":\"$kind\",\"final\":\"$final_name\"}" 2>/dev/null || true
            exit 1
        fi

        echo "[atomic_write_layer] Renaming to final path: $final_name" >&2
        # shellcheck disable=SC2086
        if ! $_batch_pfx mv -n "$tmp_path" "$final_path" 2>/dev/null; then
            echo "[atomic_write_layer] ERROR: atomic rename failed: $tmp_path -> $final_path" >&2
            exit 1
        fi
        # mv -n is silent on collision — verify the move actually happened.
        if [ ! -e "$final_path" ] || [ -e "$tmp_path" ]; then
            echo "[atomic_write_layer] ERROR: rename did not complete (race or fs error)" >&2
            exit 1
        fi

        # Rename succeeded — trap should no longer delete the tempfile.
        # Override the trap to no-op (file is now at final_path, not tmp_path).
        trap '' EXIT

        # ----- Step 7: fsync parent directory (best-effort) ---------------------
        # 'sync -f <path>' may not be available everywhere; fall back to global sync.
        # shellcheck disable=SC2086
        $_batch_pfx sync "$persist_path" 2>/dev/null || $_batch_pfx sync 2>/dev/null || sync

        # ----- Step 8: Lifecycle log: success -----------------------------------
        lifecycle_log "info" "atomic_write_layer" "atomic_write_ok" \
            "{\"type\":\"$type\",\"id\":\"$id\",\"kind\":\"$kind\",\"final\":\"$final_name\",\"sha256\":\"$sha256\",\"bytes\":$byte_count}" 2>/dev/null || true

        # Print ONLY the final basename to stdout (callers capture this)
        echo "$final_name"
    )

    local subshell_exit=$?

    if [ $subshell_exit -ne 0 ] || [ -z "$result_name" ]; then
        lifecycle_log "error" "atomic_write_layer" "atomic_write_failed" \
            "{\"type\":\"$type\",\"id\":\"$id\",\"kind\":\"$kind\",\"final\":\"$final_name\"}" 2>/dev/null || true
        echo "[atomic_write_layer] ERROR: atomic write failed (exit $subshell_exit)" >&2
        return 1
    fi

    # Pass the captured basename to our caller
    echo "$result_name"
    return 0
}
