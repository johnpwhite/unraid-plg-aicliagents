#!/bin/bash
# resolve_paths.sh — Canonical storage path resolver for all AICliAgents shell scripts.
#
# Usage:
#   source /usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/resolve_paths.sh
#
# After sourcing, the following functions are available. Each echoes the resolved value.
# All functions tolerate a missing config file (return safe defaults).
#
# Functions:
#   agent_persist_path          — echo path
#   home_persist_path <user>    — echo path
#   manifest_path               — echo path
#   lifecycle_log_path          — echo path
#   home_mount <user>           — echo path
#   agent_mount <agent_id>      — echo path
#   zram_upper <type> <id>      — echo path
#   zram_work  <type> <id>      — echo path
#
# Lifecycle log writer (pure-bash, same format as PHP LifecycleLogService):
#   lifecycle_log <level> <component> <event> [json_payload]

# ---- Constants ---------------------------------------------------------------

PLUGIN_BASE="/boot/config/plugins/unraid-aicliagents"
MOUNT_ROOT="/tmp/unraid-aicliagents"
ZRAM_BASE="$MOUNT_ROOT/zram_upper"
CONFIG_FILE="$PLUGIN_BASE/unraid-aicliagents.cfg"
EMHTTP_AGENTS="/usr/local/emhttp/plugins/unraid-aicliagents/agents"

# ---- Internal helpers -------------------------------------------------------

# _rp_read_cfg <key>
# Reads a single key from the INI-style cfg file using grep.
# Echoes the value (without quotes) or empty string if absent/unreadable.
_rp_read_cfg() {
    local key="$1"
    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return
    fi
    # Pattern: key="value" or key=value — strip surrounding quotes
    grep -oP "^${key}=\"?\K[^\"]*(?=\"?$)" "$CONFIG_FILE" 2>/dev/null | head -1
}

# _rp_normalize <path>
# Strips trailing slash, collapses double slashes.
_rp_normalize() {
    local p="$1"
    # Collapse double+ slashes (preserve leading /)
    p=$(echo "$p" | sed 's|/\{2,\}|/|g')
    # Strip trailing slash (but don't strip root /)
    p="${p%/}"
    echo "$p"
}

# _rp_normalize_user <user>
# Coerces numeric uid 0 to 'root'.
_rp_normalize_user() {
    local u="$1"
    if [ "$u" = "0" ]; then
        echo "root"
    else
        echo "$u"
    fi
}

# ---- Path functions ----------------------------------------------------------

agent_persist_path() {
    local p
    p=$(_rp_read_cfg "agent_storage_path")
    if [ -z "$p" ]; then
        p="$PLUGIN_BASE"
    fi
    _rp_normalize "$p"
}

# home_persist_path <user>
home_persist_path() {
    local user
    user=$(_rp_normalize_user "${1:-root}")

    local p
    p=$(_rp_read_cfg "home_storage_path")
    if [ -z "$p" ]; then
        p=$(_rp_read_cfg "agent_storage_path")
    fi
    if [ -z "$p" ]; then
        p="$PLUGIN_BASE/persistence"
    fi
    _rp_normalize "$p"
}

manifest_path() {
    # #1254: AICLI_MANIFEST_PATH redirects the manifest off USB flash for the L3.5
    # suite (test-only hook; unset in production -> the flash path).
    echo "${AICLI_MANIFEST_PATH:-$PLUGIN_BASE/layer_manifest.json}"
}

lifecycle_log_path() {
    echo "${AICLI_LIFECYCLE_LOG:-$PLUGIN_BASE/lifecycle.log}"
}

# home_mount <user>
home_mount() {
    local user
    user=$(_rp_normalize_user "${1:-root}")
    _rp_normalize "$MOUNT_ROOT/work/$user/home"
}

# agent_mount <agent_id>
agent_mount() {
    local id="${1:-}"
    _rp_normalize "$EMHTTP_AGENTS/$id"
}

# zram_upper <type> <id>
# type: home or agent
zram_upper() {
    local type="${1:-}"
    local id="${2:-}"
    _rp_normalize "$ZRAM_BASE/${type}s/$id/upper"
}

# zram_work <type> <id>
zram_work() {
    local type="${1:-}"
    local id="${2:-}"
    _rp_normalize "$ZRAM_BASE/${type}s/$id/work"
}

# ---- Lifecycle log writer ---------------------------------------------------

# lifecycle_log <level> <component> <event> [json_payload]
#
# Appends one structured line to the lifecycle log on flash.
# Line format: <iso8601_ts> | <level> | <component> | <event> | <json_payload>
#
# Uses `flock` for concurrent-write safety. Rotates if file exceeds 1 MB.
# No external dependencies beyond bash + coreutils (stat, mv, date).
#
# Rotation: current → .1, .1 → .2, .2 → .3, .3 dropped. Keeps 3 generations.

_LIFECYCLE_LOG_MAX_BYTES=1048576   # 1 MB
_LIFECYCLE_LOG_GENERATIONS=3

lifecycle_log() {
    local level="${1:-info}"
    local component="${2:-shell}"
    local event="${3:-}"
    local payload_json="${4:-{}}"

    local log_file
    log_file=$(lifecycle_log_path)
    local log_dir
    log_dir=$(dirname "$log_file")

    # Ensure directory exists
    [ -d "$log_dir" ] || mkdir -p "$log_dir" 2>/dev/null || return 1

    # Auto-rotate before writing
    _lifecycle_rotate_if_needed "$log_file"

    # R-06 (#1370): merge the inherited trace id into the payload as an additive
    # "_trace" key (mirrors PHP LifecycleLogService). Only when the payload is a
    # JSON object AND doesn't already carry one; the id shape is validated at
    # every producer ([a-z0-9]{4,16}), and a malformed env value is dropped here
    # too so it can never corrupt the JSON.
    if [ -n "${AICLI_TRACE_ID:-}" ]; then
        case "$AICLI_TRACE_ID" in
            *[!a-z0-9]*) : ;;  # malformed — skip
            *)
                if [ "${#AICLI_TRACE_ID}" -ge 4 ] && [ "${#AICLI_TRACE_ID}" -le 16 ]; then
                    case "$payload_json" in
                        *'"_trace"'*) : ;;  # already present
                        "{}") payload_json="{\"_trace\":\"$AICLI_TRACE_ID\"}" ;;
                        \{*)  payload_json="{\"_trace\":\"$AICLI_TRACE_ID\",${payload_json#\{}" ;;
                    esac
                fi
                ;;
        esac
    fi

    # Build the log line
    local ts
    ts=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || echo '1970-01-01T00:00:00Z')
    local line
    line="${ts} | ${level} | ${component} | ${event} | ${payload_json}"

    # Append with exclusive lock (fd 9)
    # The lock file is on /var/run (tmpfs), not on /boot (flash).
    local lock_file="/var/run/aicli-lifecycle-log.lock"
    (
        flock -x 9 2>/dev/null
        printf '%s\n' "$line" >> "$log_file"
    ) 9>>"$lock_file" 2>/dev/null

    return 0
}

# _lifecycle_rotate_if_needed <log_file>
_lifecycle_rotate_if_needed() {
    local log_file="$1"

    [ -f "$log_file" ] || return 0

    local size
    size=$(stat -c '%s' "$log_file" 2>/dev/null || echo 0)
    [ "$size" -ge "$_LIFECYCLE_LOG_MAX_BYTES" ] || return 0

    # Shift: drop .3, rename .2→.3, .1→.2, current→.1
    local gen
    for gen in $( seq $_LIFECYCLE_LOG_GENERATIONS -1 1 ); do
        local src="${log_file}.${gen}"
        local dst="${log_file}.$((gen + 1))"
        if [ -f "$src" ]; then
            if [ "$gen" -eq "$_LIFECYCLE_LOG_GENERATIONS" ]; then
                rm -f "$src" 2>/dev/null
            else
                mv -f "$src" "$dst" 2>/dev/null
            fi
        fi
    done

    mv -f "$log_file" "${log_file}.1" 2>/dev/null
}
