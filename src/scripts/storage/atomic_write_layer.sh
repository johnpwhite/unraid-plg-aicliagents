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
#   delta:        ${type}_${id}_delta_${dt}.sqsh
#   consolidated: ${type}_${id}_consolidated_${dt}.sqsh
#   where ${dt} = $(date -u +%Y%m%dT%H%M%SZ) — UTC, ISO 8601 basic, 16 chars.
# Legacy formats remain valid lower layers but are not produced by this writer.
#
# Returns 0 on success and prints the final basename to stdout.
# Returns non-zero on any failure; prints nothing to stdout.
# All progress/diagnostic output goes to stderr.
#
# Environment:
#   MKSQUASHFS_ARGS — override mksquashfs flags (default: xz, x86 BCJ, 1M block)
#
# Lifecycle log events emitted:
#   atomic_write_start, atomic_write_verify_failed, atomic_write_ok, atomic_write_failed

# Source canonical path resolver (for lifecycle_log) — tolerate missing.
# Only source if not already sourced (idempotent).
if ! declare -f lifecycle_log >/dev/null 2>&1; then
    _AWLSH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"
    source "${_AWLSH_DIR}/resolve_paths.sh" 2>/dev/null || true
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
    # dt: UTC ISO 8601 basic (YYYYMMDDTHHMMSSZ) — 16 chars, lex-sortable, human-readable.
    local dt
    dt=$(date -u +%Y%m%dT%H%M%SZ)
    local epoch
    epoch=$(date +%s)

    local final_name
    if [ "$kind" = "delta" ]; then
        final_name="${type}_${id}_delta_${dt}.sqsh"
    else
        final_name="${type}_${id}_consolidated_${dt}.sqsh"
    fi

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

        # shellcheck disable=SC2086
        if ! mksquashfs "$upper_dir" "$tmp_path" $mksq_args > /dev/null 2>&1; then
            echo "[atomic_write_layer] ERROR: mksquashfs failed" >&2
            exit 1
        fi

        # ----- Step 3: fsync the tempfile (full sync — safe on all filesystems) -
        echo "[atomic_write_layer] Syncing to disk..." >&2
        sync

        # ----- Step 4: Compute sha256 ------------------------------------------
        local sha256
        sha256=$(sha256sum "$tmp_path" 2>/dev/null | awk '{print $1}')
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
        if ! mv -n "$tmp_path" "$final_path" 2>/dev/null; then
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
        sync "$persist_path" 2>/dev/null || sync

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
