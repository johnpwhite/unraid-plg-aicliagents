#!/bin/bash
set -euo pipefail
# AICliAgents: Deterministic Background Migration Engine
# Migrates legacy User Homes and Agent Binaries to SquashFS stacks.
# 1. State (workspaces.json/envs) is moved into the user's home directory (~/.aicli/).
# 2. Valid Unraid users are determined via /etc/passwd.
# 3. Agents are converted but originals are deleted to save space.
# 4. Homes are converted and originals are moved to migrated_legacy_data/.

LOCK_FILE="/tmp/unraid-aicliagents/migration.lock"
LOG_FILE="/tmp/unraid-aicliagents/migration.log"
DEBUG_LOG="/tmp/unraid-aicliagents/debug.log"
CONFIG_FILE="/boot/config/plugins/unraid-aicliagents/unraid-aicliagents.cfg"

# Source shared storage functions (guard_path, check_disk_space, get_ts, etc.)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../storage/common.sh"
# Source canonical path resolver (Phase 5 — replaces inline cfg greps)
source "$SCRIPT_DIR/../storage/resolve_paths.sh" 2>/dev/null || true

mkdir -p "$(dirname "$LOCK_FILE")"

# Use flock to prevent concurrent migration runs
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "[$(get_ts)] [WARN] [MIGRATION] Another migration is already running. Exiting." >> "$DEBUG_LOG"
    exit 0
fi
# Lock acquired — lock file will be removed in trap
trap 'rm -f "$LOCK_FILE"; exec 9>&-' EXIT

log() {
    local msg="[$(get_ts)] [INFO] [MIGRATION] $1"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE"
    echo "$msg" >> "$DEBUG_LOG"
}

# D-402: Publish migration progress via Nchan (fire-and-forget via curl)
publish_progress() {
    local step="$1"
    local progress="${2:-0}"
    local json="{\"step\":\"$step\",\"progress\":$progress,\"timestamp\":$(date +%s)}"
    curl -s -X POST "http://localhost/pub/aicli_migration?buffer_length=1" \
        -H "Content-Type: application/json" -d "$json" --connect-timeout 1 --max-time 2 > /dev/null 2>&1 || true
}

# Helper: Check if a directory has actual content (files, not just empty subdirs)
dir_has_content() {
    local dir="$1"
    [ -d "$dir" ] || return 1
    # Check for any regular files (recursive, limit to 1 for speed)
    local count
    count=$(find "$dir" -maxdepth 3 -type f 2>/dev/null | head -1 | wc -l)
    [ "$count" -gt 0 ]
}

# 0. Check for mksquashfs
if ! command -v mksquashfs >/dev/null 2>&1; then
    log "ERROR: mksquashfs not found! Migration cannot proceed."
    exit 1
fi

# 1. Determine Persistence Paths via canonical resolver (Phase 5 — no inline cfg greps)
PLUGIN_BASE="/boot/config/plugins/unraid-aicliagents"
AGENT_PERSIST_PATH=$(agent_persist_path 2>/dev/null || echo "$PLUGIN_BASE")
HOME_PERSIST_PATH=$(home_persist_path root 2>/dev/null || echo "$PLUGIN_BASE/persistence")
# Legacy compat: PERSIST_PATH used for agent operations and monolithic image search
PERSIST_PATH="$AGENT_PERSIST_PATH"
MIGRATED_DIR="$AGENT_PERSIST_PATH/migrated_legacy_data"

# Validate persistence path
guard_path "$PERSIST_PATH" "PERSIST_PATH" || { log "ERROR: Persistence path failed validation: $PERSIST_PATH"; exit 1; }

# Get the primary configured user
CFG_USER="root"
if [ -f "$CONFIG_FILE" ]; then
    TMP_USER=$(grep -oP '^user="\K[^"]+' "$CONFIG_FILE") || true
    [ -n "${TMP_USER:-}" ] && [ "$TMP_USER" != "0" ] && CFG_USER="$TMP_USER"
fi

# Get all valid Unraid users
VALID_USERS=$(awk -F':' '{ if ($3 == 0 || ($3 >= 1000 && $3 < 60000)) print $1 }' /etc/passwd)

# ── Early exit: Check if there is anything to migrate ──
# Only proceed if legacy .img files OR agent folders with actual content exist.
has_legacy=false

# Check for legacy .img files (in both plugin base and persistence path)
shopt -s nullglob
for img in "$PLUGIN_BASE"/*.img "$PLUGIN_BASE"/persistence/*.img "$PERSIST_PATH"/*.img "$PERSIST_PATH"/persistence/*.img "$HOME_PERSIST_PATH"/*.img; do
    [ -f "$img" ] && has_legacy=true && break
done

# Check for raw agent folders with actual files (empty mount-point dirs are not legacy)
RAW_AGENTS="/usr/local/emhttp/plugins/unraid-aicliagents/agents"
if [ "$has_legacy" = false ] && [ -d "$RAW_AGENTS" ]; then
    for agent_dir in "$RAW_AGENTS"/*; do
        [ -d "$agent_dir" ] || continue
        AGENT_ID=$(basename "$agent_dir")
        [[ "$AGENT_ID" =~ ^(\.|\.\.|bin|includes|scripts|assets)$ ]] && continue
        mountpoint -q "$agent_dir" && continue
        # Only count as legacy if the directory has actual files (not just an empty mount point)
        dir_has_content "$agent_dir" && has_legacy=true && break
    done
fi
shopt -u nullglob

if [ "$has_legacy" = false ]; then
    log "No legacy storage artifacts found. Storage is already SquashFS. Skipping migration."
    # Clean up any empty mount-point directories left by cleanup.sh
    if [ -d "$RAW_AGENTS" ]; then
        shopt -s nullglob
        for agent_dir in "$RAW_AGENTS"/*; do
            [ -d "$agent_dir" ] || continue
            AGENT_ID=$(basename "$agent_dir")
            [[ "$AGENT_ID" =~ ^(\.|\.\.|bin|includes|scripts|assets)$ ]] && continue
            mountpoint -q "$agent_dir" && continue
            if ! dir_has_content "$agent_dir"; then
                rmdir "$agent_dir" 2>/dev/null || true
            fi
        done
        shopt -u nullglob
    fi
    exit 0
fi

log "Starting deterministic migration..."
log "Agent storage path: $AGENT_PERSIST_PATH"
log "Home storage path: $HOME_PERSIST_PATH"
publish_progress "Starting migration..." 5

# 2. Localize State (Move workspaces.json and envs/ to the Configured User's Home)
# Only run if the user does NOT already have SquashFS volumes (state is already inside)
localize_state() {
    local target_dir="$1"
    local legacy_ws="/boot/config/plugins/unraid-aicliagents/workspaces.json"
    local legacy_envs="/boot/config/plugins/unraid-aicliagents/envs"

    if [ -f "$legacy_ws" ] || [ -d "$legacy_envs" ]; then
        log "Localizing legacy state (workspaces/envs) to $target_dir/.aicli ..."
        mkdir -p "$target_dir/.aicli"
        [ -f "$legacy_ws" ] && mv "$legacy_ws" "$target_dir/.aicli/workspaces.json"
        if [ -d "$legacy_envs" ]; then
            cp -a "$legacy_envs" "$target_dir/.aicli/"
            if [ $? -eq 0 ]; then rm -rf "$legacy_envs"; fi
        fi
    fi
}

# Skip localization if user already has SquashFS home (state is already inside the sqsh)
if ! compgen -G "$HOME_PERSIST_PATH/home_${CFG_USER}_*.sqsh" > /dev/null 2>&1; then
    TARGET_HOME_RAW="$PERSIST_PATH/persistence/$CFG_USER"
    [ ! -d "$TARGET_HOME_RAW" ] && [ -d "$PERSIST_PATH/$CFG_USER" ] && TARGET_HOME_RAW="$PERSIST_PATH/$CFG_USER"

    if [ -d "$TARGET_HOME_RAW" ]; then
        localize_state "$TARGET_HOME_RAW"
    else
        TARGET_HOME_MNT="/tmp/unraid-aicliagents/work/$CFG_USER/home"
        if ! mountpoint -q "$TARGET_HOME_MNT"; then
            log "Target home ($CFG_USER) not mounted. Attempting temporary mount for localization..."
            bash "$SCRIPT_DIR/../storage/mount_stack.sh" home "$CFG_USER" "$PERSIST_PATH" >/dev/null 2>&1 || true
        fi
        if mountpoint -q "$TARGET_HOME_MNT"; then
            localize_state "$TARGET_HOME_MNT"
        else
            log "Warning: Target home ($CFG_USER) could not be accessed. State localization deferred to runtime."
        fi
    fi
else
    log "Home for $CFG_USER already has SquashFS volumes. Skipping state localization."
fi

publish_progress "Converting agent binaries..." 15

# 2b. Extract monolithic aicli-agents.img (Era 2 legacy format)
# The old architecture stored ALL agents in a single Btrfs image.
# We need to mount it and copy each agent subfolder into /agents/ as raw folders,
# then Step 3 will convert each one to individual SquashFS volumes.
MONO_IMG=""
for candidate in "$PLUGIN_BASE/aicli-agents.img" "$PERSIST_PATH/aicli-agents.img"; do
    [ -f "$candidate" ] && MONO_IMG="$candidate" && break
done

if [ -n "$MONO_IMG" ]; then
    log "Found monolithic agent image: $MONO_IMG"
    publish_progress "Extracting monolithic agent image..." 20
    MONO_MNT="/tmp/unraid-aicliagents/mnt/legacy_agents_mono"
    mkdir -p "$MONO_MNT"
    if mount -o loop,ro "$MONO_IMG" "$MONO_MNT" 2>/dev/null; then
        mkdir -p "$RAW_AGENTS"
        for agent_src in "$MONO_MNT"/*; do
            [ -d "$agent_src" ] || continue
            AGENT_ID=$(basename "$agent_src")
            # Skip system dirs that might be inside the image
            [[ "$AGENT_ID" =~ ^(\.|\.\.|\.\w+|bin|includes|scripts|assets|\.runtime|node_modules)$ ]] && continue
            # Skip if already extracted or sqsh exists
            if [ -d "$RAW_AGENTS/$AGENT_ID" ] && dir_has_content "$RAW_AGENTS/$AGENT_ID"; then
                log "  > Agent $AGENT_ID already extracted. Skipping."
                continue
            fi
            if compgen -G "$PERSIST_PATH/agent_${AGENT_ID}_*.sqsh" > /dev/null 2>&1; then
                log "  > Agent $AGENT_ID already has SquashFS. Skipping extraction."
                continue
            fi
            log "  > Extracting agent: $AGENT_ID"
            mkdir -p "$RAW_AGENTS/$AGENT_ID"
            cp -a "$agent_src"/* "$RAW_AGENTS/$AGENT_ID/"
        done
        umount -l "$MONO_MNT" || true
        # Move the monolithic image to migrated_legacy_data
        mkdir -p "$MIGRATED_DIR/agents"
        mv "$MONO_IMG" "$MIGRATED_DIR/agents/"
        log "Monolithic agent image extracted and archived."
    else
        log "ERROR: Failed to mount monolithic agent image: $MONO_IMG"
    fi
    rmdir "$MONO_MNT" 2>/dev/null || true
fi

publish_progress "Converting agent binaries..." 25

# 3. Process Agent Binaries (raw folders → individual SquashFS)
if [ -d "$RAW_AGENTS" ]; then
    shopt -s nullglob
    for agent_dir in "$RAW_AGENTS"/*; do
        [ -d "$agent_dir" ] || continue
        AGENT_ID=$(basename "$agent_dir")

        [[ "$AGENT_ID" =~ ^(\.|\.\.|bin|includes|scripts|assets)$ ]] && continue

        mountpoint -q "$agent_dir" && continue

        # Skip empty mount-point directories (left behind after cleanup.sh unmounts)
        if ! dir_has_content "$agent_dir"; then
            log "  > Removing empty mount point: $AGENT_ID"
            rmdir "$agent_dir" 2>/dev/null || true
            continue
        fi

        # Skip if ANY sqsh files exist for this agent (not just _v1_vol1)
        if compgen -G "$PERSIST_PATH/agent_${AGENT_ID}_*.sqsh" > /dev/null 2>&1; then
            log "  > Agent $AGENT_ID already has SquashFS volumes. Deleting legacy folder."
            rm -rf "$agent_dir"
            continue
        fi

        log "  > Converting agent: $AGENT_ID"
        publish_progress "Converting agent: $AGENT_ID" 30
        if mksquashfs "$agent_dir" "$PERSIST_PATH/agent_${AGENT_ID}_v1_vol1.sqsh" \
            -comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend >> "$LOG_FILE" 2>&1; then
            rm -rf "$agent_dir"
        else
            log "  > ERROR: Failed to convert agent $AGENT_ID. Legacy folder preserved."
        fi
    done
    shopt -u nullglob
fi

publish_progress "Converting user homes..." 50

# 4. Process User Homes
mkdir -p "$MIGRATED_DIR/homes"
shopt -s nullglob

# 4a. Legacy .img files
for home_img in "$PERSIST_PATH/persistence/home_"*.img "$PERSIST_PATH/home_"*.img "$HOME_PERSIST_PATH/home_"*.img; do
    [ -f "$home_img" ] || continue
    USER_NAME=$(basename "$home_img" | sed 's/home_//' | sed 's/\.img.*//')

    if ! echo "$VALID_USERS" | grep -qw "$USER_NAME"; then
        log "Skipping .img for non-Unraid user: $USER_NAME"
        continue
    fi

    # Skip if ANY sqsh files exist for this user
    if compgen -G "$HOME_PERSIST_PATH/home_${USER_NAME}_*.sqsh" > /dev/null 2>&1; then
        log "  > Home $USER_NAME already converted. Moving image to backup."
        mv "$home_img" "$MIGRATED_DIR/homes/"
        continue
    fi

    log "  > Migrating home image: $USER_NAME"
    publish_progress "Migrating home: $USER_NAME" 60
    MNT="/tmp/unraid-aicliagents/mnt/legacy_home_$USER_NAME"
    mkdir -p "$MNT"
    if mount -o loop,ro "$home_img" "$MNT"; then
        mksquashfs "$MNT" "$HOME_PERSIST_PATH/home_${USER_NAME}_v1_vol1.sqsh" \
            -comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend >> "$LOG_FILE" 2>&1 || true
        umount -l "$MNT" || true
        mv "$home_img" "$MIGRATED_DIR/homes/"
    fi
done

# 4b. Raw folders (only from plugin base, NOT from persist path which may contain runtime dirs)
SKIP_DIRS="envs|persistence|pkg-cache|test-fixtures|migrated_legacy_data|includes|scripts|assets|bin"

for home_parent in "$PLUGIN_BASE/persistence" "$PLUGIN_BASE" "$HOME_PERSIST_PATH"; do
    [ -d "$home_parent" ] || continue
    for home_dir in "$home_parent"/*; do
        [ -d "$home_dir" ] || continue
        USER_NAME=$(basename "$home_dir")

        [[ "$USER_NAME" =~ ^($SKIP_DIRS)$ ]] && continue

        if ! echo "$VALID_USERS" | grep -qw "$USER_NAME"; then
            continue
        fi

        mountpoint -q "$home_dir" && continue

        # Skip if this user already has ANY .sqsh file
        if compgen -G "$HOME_PERSIST_PATH/home_${USER_NAME}_*.sqsh" > /dev/null 2>&1; then
            log "  > Home $USER_NAME already has SquashFS volumes. Skipping raw folder."
            continue
        fi

        log "  > Converting raw home folder: $USER_NAME"
        publish_progress "Converting home: $USER_NAME" 70
        if mksquashfs "$home_dir" "$HOME_PERSIST_PATH/home_${USER_NAME}_v1_vol1.sqsh" \
            -comp xz -Xbcj x86 -Xdict-size 100% -b 1M -no-exports -noappend >> "$LOG_FILE" 2>&1; then
            mv "$home_dir" "$MIGRATED_DIR/homes/"
        fi
    done
done
shopt -u nullglob

# 5. Clean up empty migrated_legacy_data if nothing was actually migrated
if [ -d "$MIGRATED_DIR" ]; then
    if [ -z "$(find "$MIGRATED_DIR" -type f 2>/dev/null)" ]; then
        rm -rf "$MIGRATED_DIR"
        log "No artifacts generated — removed empty migrated_legacy_data/."
    fi
fi

publish_progress "Migration complete." 100
log "Deterministic migration finished."
# Lock file removed by EXIT trap
