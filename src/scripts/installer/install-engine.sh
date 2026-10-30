#!/bin/bash
# AICliAgents Modular Installer Engine (v44)
# Optimized for Unraid standard practices and UI stability.

# Only one plugin lifecycle transaction may stage/activate at a time.  The fd
# remains open for the entire installer, so every failure releases it safely.
INSTALL_LOCK="${AICLI_INSTALL_LOCK:-/var/lock/unraid-aicliagents-install.lock}"
mkdir -p "$(dirname "$INSTALL_LOCK")"
exec 9>"$INSTALL_LOCK"
if ! flock -n 9; then
    log_fail "Another AICliAgents install/update is already running."
    exit 1
fi

# --- Path Telemetry & Environment ---
REAL_EMHTTP=$(readlink -f "$EMHTTP_DEST")
log_status "  -----------------------------------------------------------"
log_status "  AICliAgents Environment Analysis"
log_status "  -----------------------------------------------------------"
log_status "  Plugin:    $NAME (v$VERSION)"
log_status "  Path:      $EMHTTP_DEST"
log_status "  -----------------------------------------------------------"

# -----------------------------------------------------------------
#  [1/5]  ENVIRONMENT SANITATION
# -----------------------------------------------------------------
log_progress "5"
log_step "[1/5] Preparing Environment..."

# Deployment Aliases
log_status "  > Preparing plugin directory..."
[ -d "$EMHTTP_DEST" ] || mkdir -p "$EMHTTP_DEST"
cd "$EMHTTP_DEST"

# -----------------------------------------------------------------
#  [2/5]  PAYLOAD EXTRACTION & UI DEPLOYMENT
# -----------------------------------------------------------------
log_progress "20"
log_step "[2/5] Restoring Backend Payload..."

# --- Payload check (R-019, #597) ----------------------------------------------
# Unraid fetches a <FILE> only when the cached copy is missing. A truncated
# download or a proxy error page that was saved under the cache name therefore
# stayed there, and every install and every boot of this version failed on it
# until a person deleted the file by hand. The check below finds such a file,
# deletes the flash cache copy and fetches the payload once more. A SHA-256
# declared in the .plg (<SHA256> in the <FILE> entry, exported by the installer as
# AICLI_SRC_SHA256) is enforced as well; with none declared, the archive must
# still be a complete, readable tar.gz.
# BEGIN payload-check
aicli_payload_ok() {   # <file> [expected_sha256] -> 0 when the archive is complete
    local f="${1:-}" want="${2:-}"
    [ -s "$f" ] || return 1
    gzip -t "$f" 2>/dev/null || return 1
    tar -tzf "$f" >/dev/null 2>&1 || return 1
    [ -z "$want" ] && return 0
    [ "$(sha256sum "$f" 2>/dev/null | awk '{print $1}')" = "$want" ]
}
aicli_ensure_src_payload() {   # verify /tmp/aicli-src.tar.gz; on failure fetch it again
    local tmp="${AICLI_SRC_TMP:-/tmp/aicli-src.tar.gz}"
    local cache="${CONFIG_DIR}/aicli-src-${VERSION}.tar.gz"
    local url="${AICLI_SRC_URL:-${GIT_URL}/src.tar.gz}"
    local want="${AICLI_SRC_SHA256:-}"
    if aicli_payload_ok "$tmp" "$want"; then return 0; fi
    log_status "  > The cached source payload is damaged or incomplete. Deleting it and downloading it again..."
    # The damaged flash copy goes first: even when the new download fails, the
    # next install fetches a fresh file instead of reading this one again.
    rm -f "$tmp" "$cache"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 2 --max-time 300 -o "$tmp.dl" "$url" 2>/dev/null || rm -f "$tmp.dl"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -T 60 -O "$tmp.dl" "$url" 2>/dev/null || rm -f "$tmp.dl"
    fi
    if [ -f "$tmp.dl" ] && aicli_payload_ok "$tmp.dl" "$want"; then
        mkdir -p "$(dirname "$cache")" 2>/dev/null
        cp -f "$tmp.dl" "$cache.tmp.$$" 2>/dev/null && mv -f "$cache.tmp.$$" "$cache" 2>/dev/null || rm -f "$cache.tmp.$$"
        mv -f "$tmp.dl" "$tmp"
        log_status "  > Source payload downloaded again and checked."
        return 0
    fi
    rm -f "$tmp.dl"
    return 1
}
# END payload-check
if ! aicli_ensure_src_payload; then
    log_fail "Source payload (src.tar.gz) is damaged and could not be downloaded again. Check the network, then install again; the damaged cache copy is deleted."
    exit 1
fi

# Extract the unified src.tar.gz into a private staging directory.  Nothing in
# the active generation is modified until validation and the migration gate
# have both passed.
if [ -f "/tmp/aicli-src.tar.gz" ]; then
    GENERATIONS_DIR="$EMHTTP_DEST/.generations"
    STAGING_ROOT="$GENERATIONS_DIR/.staging-${VERSION//[^A-Za-z0-9._-]/_}-$$"
    mkdir -p "$STAGING_ROOT"
    cleanup_staged_generation() {
        case "${STAGING_ROOT:-}" in
            "$GENERATIONS_DIR"/.staging-*) [ -d "$STAGING_ROOT" ] && rm -rf -- "$STAGING_ROOT" ;;
        esac
    }
    trap cleanup_staged_generation EXIT
    log_status "  > Staging source payload (src.tar.gz) for validation..."
    if tar -xzf /tmp/aicli-src.tar.gz -C "$STAGING_ROOT"; then
        # D-187: normalise the private staging tree before validation and
        # activation. Once activated, a generation is never edited in place.
        find "$STAGING_ROOT/src" -type f \( -name "*.php" -o -name "*.page" -o -name "*.sh" -o -name "*.js" -o -name "*.css" \) -exec sed -i 's/\r//g' {} +
        # The helper is part of the staged payload and is syntax/shape checked
        # before it is trusted with activation.
        if ! bash -n "$STAGING_ROOT/src/scripts/installer/generation.sh"; then
            log_fail "Generation helper failed syntax validation; active plugin left unchanged."
            exit 1
        fi
        source "$STAGING_ROOT/src/scripts/installer/generation.sh"
        if ! aicli_validate_staged_payload "$STAGING_ROOT"; then
            log_fail "Source payload validation failed; active plugin left unchanged."
            exit 1
        fi
        GENERATION_ID=$(aicli_payload_generation_id /tmp/aicli-src.tar.gz "$VERSION") || {
            log_fail "Could not calculate payload generation id."
            exit 1
        }

        # HARD_STOP_RESILIENCE R2: after a reboot the RAM copy of the layout
        # marker is gone and the PLG passes "0". Use the flash copy as the OLD
        # layout version so a normal reboot is not read as a layout change.
        # No flash copy yet (first run after this change): nothing changes.
        _LV_FLASH="${AICLI_LAYOUT_MARKER_DIR:-$CONFIG_DIR}/.layout-version"
        if [ "${OLD_LAYOUT_VERSION:-0}" = "0" ] && [ -f "$_LV_FLASH" ]; then
            _LV_F=$(tr -d '[:space:]' < "$_LV_FLASH" 2>/dev/null || true)
            [[ "$_LV_F" =~ ^[0-9]+$ ]] && [ "$_LV_F" != "0" ] && OLD_LAYOUT_VERSION="$_LV_F"
        fi

        # A layout migration is intentionally destructive.  Defer it while any
        # ttyd session is alive rather than closing agents behind the user's
        # back.  The staged payload remains inert and the migration sentinel is
        # not consumed, so a later retry is deterministic.
        if [ "${UPGRADE_MODE:-0}" = "1" ] \
            && aicli_stage_requires_migration "$STAGING_ROOT" "${OLD_LAYOUT_VERSION:-0}" "$CONFIG_DIR" \
            && aicli_has_live_sessions; then
            log_warn "Update requires a storage-layout migration and was deferred because agent sessions are running. Close them and retry the update."
            exit 2
        fi

        # Required runtime gates publication, service/storage initialization and
        # the parent's success/autolaunch path. Always recheck on reboot/reuse.
        log_step "Checking required runtime before backend activation..."
        if [ ! -s /tmp/aicli-runtime.sh ] || ! bash -n /tmp/aicli-runtime.sh; then
            log_fail "Runtime installer missing or invalid; activation blocked."
            exit 1
        fi
        bash /tmp/aicli-runtime.sh
        runtime_rc=$?
        if [ "$runtime_rc" -ne 0 ]; then
            log_fail "Runtime provisioning failed (rc=$runtime_rc); activation, services and auto-launch blocked."
            exit "$runtime_rc"
        fi

        if ! aicli_activate_generation "$EMHTTP_DEST" "$STAGING_ROOT" "$GENERATION_ID"; then
            log_fail "Atomic generation activation failed; prior generation remains available."
            exit 1
        fi
        log_ok "Backend generation $GENERATION_ID validated and activated atomically."
        # Keep a copy of the new layout marker on flash (temp file + mv).
        _LV_NEW=$(tr -d '[:space:]' < "$EMHTTP_DEST/src/.layout-version" 2>/dev/null || true)
        if [[ "$_LV_NEW" =~ ^[0-9]+$ ]] && mkdir -p "$(dirname "$_LV_FLASH")" 2>/dev/null; then
            printf '%s\n' "$_LV_NEW" > "$_LV_FLASH.tmp.$$" 2>/dev/null \
                && mv -f "$_LV_FLASH.tmp.$$" "$_LV_FLASH" 2>/dev/null \
                || rm -f "$_LV_FLASH.tmp.$$" 2>/dev/null || true
        fi
    else
        log_fail "Failed to extract source payload; active plugin left unchanged."
        exit 1
    fi
else
    log_fail "Payload archive (src.tar.gz) missing from /tmp; active plugin left unchanged."
    exit 1
fi

# Only after a validated generation is active may replaceable root entry
# points be refreshed. A corrupt/missing archive therefore leaves the complete
# old plugin usable, not merely its src subtree. Do not purge the old entry
# points first: each replacement below must remain available until its rename.

# D-188: Use physical copies for entry points instead of symlinks.
# README.md is included so it lands at plugins/<name>/README.md where the Unraid
# Plugins page renders it as the plugin description (see ShowPlugins.php:96).
log_status "  > Deploying UI entry points..."
for f in AICliAjax.php AICliAgentsManager.page AICliAgents.page AICliMenuIcon.page AICliNote.page AICliOpenFile.page AICliWiki.page ArrayStopWarning.page README.md; do
    if [ -f "src/$f" ]; then
        cp -f "src/$f" "$f.tmp.$$" && mv -f "$f.tmp.$$" "$f"
    else
        log_warn "Source file missing: src/$f"
    fi
done
# The Relay MCP endpoint moved to src/AICliRelayMcp.php. The old root .page copy has no page header, so Unraid
# logged "Invalid .page format" for it on every page. Remove it (it is this plugin's own file).
rm -f AICliRelayMcp.page

# Directory mappings have stable targets through the src generation pointer.
# Replace an existing symlink with one rename. A legacy physical directory is
# archived on the first generation upgrade, never deleted.
for mapping in assets includes scripts; do
    target="src/$mapping"
    if [ -L "$mapping" ] && [ "$(readlink "$mapping")" = "$target" ]; then
        continue
    fi
    next_link=".$mapping.next.$$"
    ln -s "$target" "$next_link" || { log_fail "Could not stage $mapping mapping."; exit 1; }
    if [ -L "$mapping" ]; then
        mv -Tf "$next_link" "$mapping" || { log_fail "Could not activate $mapping mapping."; exit 1; }
    elif [ -e "$mapping" ]; then
        legacy_root="$EMHTTP_DEST/.legacy-root-entries"
        mkdir -p "$legacy_root"
        legacy_mapping="$legacy_root/${mapping}-$(date +%s)-$$"
        mv "$mapping" "$legacy_mapping" \
            || { rm -f "$next_link"; log_fail "Could not archive legacy $mapping directory."; exit 1; }
        mv -Tf "$next_link" "$mapping" || {
            mv "$legacy_mapping" "$mapping" 2>/dev/null || true
            log_fail "Could not activate $mapping mapping."
            exit 1
        }
    else
        mv -Tf "$next_link" "$mapping" || { log_fail "Could not activate $mapping mapping."; exit 1; }
    fi
done
log_ok "Root entry points and directory mapping complete."

# Cache Reset
/usr/bin/php -r "if(function_exists('opcache_reset')) opcache_reset();" >/dev/null 2>&1

cd - > /dev/null

# -----------------------------------------------------------------
#  [4/5]  STORAGE & SERVICE INITIALIZATION
# -----------------------------------------------------------------
log_progress "60"
log_step "[4/5] Initializing Services..."

# 0. Legacy Process Eviction
if [ -f "/tmp/aicli-clean.sh" ]; then
    bash /tmp/aicli-clean.sh
else
    log_warn "Cleanup script (aicli-clean.sh) missing. Proceeding without eviction."
fi

# 1. Storage Scaffolding
bash /tmp/aicli-storage.sh

# 2. UI Assets & Permissions (Verification only)
bash /tmp/aicli-ui.sh

# 3. Development/Legacy Migration (If needed)
bash /tmp/aicli-legacy.sh

# -----------------------------------------------------------------
#  [5/5]  FINALIZING & PERMISSIONS
# -----------------------------------------------------------------
log_progress "80"
log_step "[5/5] Finalizing Environment..."
bash /tmp/aicli-finalize.sh


# Cleanup installer scripts from /tmp
rm -f /tmp/aicli-*.sh /tmp/aicli-src.tar.gz

log_ok "Installation logic complete."
log_progress "100"
exit 0
