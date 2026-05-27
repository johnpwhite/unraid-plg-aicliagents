#!/bin/bash
# queue_helpers.sh — Flat-file queue protocol for the Storage Durability Supervisor.
#
# Queue directory: /tmp/unraid-aicliagents/supervisor/queue/
# File naming:    <priority>_<epoch>_<type>_<id>_<op>.req
#   priority: 2-digit, 00 = highest (install), 05 = user-click, 10 = event,
#             50 = dirty-pressure, 99 = schedule.
# File content:   one-liner JSON with op details.
# Enqueue:        write .tmp + rename (atomic).
# Pop:            lex-sort gives priority order; lowest file = next op.
#
# Usage (source this file):
#   source queue_helpers.sh
#   queue_enqueue 10 home root bake workspace_close
#   path=$(queue_pop_next)
#   depth=$(queue_depth)

QUEUE_DIR="${QUEUE_DIR:-/tmp/unraid-aicliagents/supervisor/queue}"

# Ensure queue directory exists
_queue_ensure_dir() {
    [ -d "$QUEUE_DIR" ] || mkdir -p "$QUEUE_DIR" 2>/dev/null || true
}

# queue_enqueue <priority> <type> <id> <op> <reason>
# priority is a number 0-99; padded to 2 digits for lex sort.
# Writes an atomic req file to the queue directory.
# Returns 0 on success, 1 on failure.
queue_enqueue() {
    local priority="${1:-99}"
    local type="${2:-}"
    local id="${3:-}"
    local op="${4:-bake}"
    local reason="${5:-manual}"

    _queue_ensure_dir

    # Pad priority to 2 digits
    local prio_padded
    prio_padded=$(printf '%02d' "$priority")

    local epoch
    epoch=$(date +%s)

    # Sanitize id for safe filename (replace / and space with _)
    local safe_id
    safe_id=$(printf '%s' "$id" | tr '/ ' '__')

    local filename="${prio_padded}_${epoch}_${type}_${safe_id}_${op}.req"
    local tmp_path="${QUEUE_DIR}/.${filename}.tmp.$$"
    local final_path="${QUEUE_DIR}/${filename}"

    # Build JSON payload
    local json
    json=$(printf '{"type":"%s","id":"%s","op":"%s","reason":"%s","queued_at":%d}' \
        "$type" "$id" "$op" "$reason" "$epoch")

    # Atomic write: tmp -> rename
    printf '%s\n' "$json" > "$tmp_path" 2>/dev/null || return 1
    mv -f "$tmp_path" "$final_path" 2>/dev/null || { rm -f "$tmp_path" 2>/dev/null; return 1; }
    return 0
}

# queue_pop_next
# Echoes the path of the next (lowest-priority) queue file, or nothing if empty.
# Does NOT delete the file — caller must delete after processing.
queue_pop_next() {
    _queue_ensure_dir
    # lex sort; first non-tmp .req file is the highest priority
    local f
    for f in "$QUEUE_DIR"/*.req; do
        [ -f "$f" ] || continue
        echo "$f"
        return 0
    done
    return 1
}

# queue_depth
# Echoes the number of pending .req files in the queue.
queue_depth() {
    _queue_ensure_dir
    local count=0
    local f
    for f in "$QUEUE_DIR"/*.req; do
        [ -f "$f" ] && count=$((count + 1))
    done
    echo "$count"
}

# queue_read_field <path> <field>
# Reads a single JSON field from a queue file (bash-only, no jq dependency).
# Only handles simple string and integer values at top level.
queue_read_field() {
    local path="$1"
    local field="$2"
    [ -f "$path" ] || return 1
    # Extract "field":"value" or "field":number
    local raw
    raw=$(grep -oP "\"${field}\":\s*\K(\"[^\"]*\"|[0-9]+)" "$path" 2>/dev/null | head -1)
    # Strip surrounding quotes if present
    raw="${raw#\"}"
    raw="${raw%\"}"
    echo "$raw"
}
