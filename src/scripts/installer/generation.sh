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
}
