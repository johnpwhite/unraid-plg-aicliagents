#!/bin/bash
# AICliAgents Modular Installer Engine (v43)
# Optimized for Unraid standard practices and UI stability.

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

# Purge legacy physical entries that collide with refactored structure
# D-192: Use -depth before -delete to satisfy find parser
find . -maxdepth 1 -type f \( -name "*.php" -o -name "*.page" -o -name "*.xml" \) -depth -delete
rm -rf assets ArrayStopWarning.page includes scripts css src

# -----------------------------------------------------------------
#  [2/5]  PAYLOAD EXTRACTION & UI DEPLOYMENT
# -----------------------------------------------------------------
log_progress "20"
log_step "[2/5] Restoring Backend Payload..."

# Extract the unified src.tar.gz bundle directly into emhttp.
if [ -f "/tmp/aicli-src.tar.gz" ]; then
    log_status "  > Extracting source payload (src.tar.gz) to $EMHTTP_DEST..."
    tar -xzf /tmp/aicli-src.tar.gz -C "$EMHTTP_DEST"
    if [ $? -eq 0 ]; then
        log_ok "Backend payload restored successfully."
        # D-187: Mandatory CRLF to LF conversion for all PHP/Page/Script files
        find "$EMHTTP_DEST/src" -type f \( -name "*.php" -o -name "*.page" -o -name "*.sh" -o -name "*.js" -o -name "*.css" \) -exec sed -i 's/\r//g' {} +
    else
        log_fail "Failed to extract source payload. System integrity compromised."
        exit 1
    fi
else
    log_warn "Payload archive (src.tar.gz) missing from /tmp. Skipping extraction."
fi

# D-188: Use physical copies for entry points instead of symlinks.
# README.md is included so it lands at plugins/<name>/README.md where the Unraid
# Plugins page renders it as the plugin description (see ShowPlugins.php:96).
log_status "  > Deploying UI entry points..."
for f in AICliAjax.php AICliAgentsManager.page AICliAgents.page ArrayStopWarning.page README.md; do
    rm -f "$f"
    if [ -f "src/$f" ]; then
        cp -f "src/$f" "$f"
    else
        log_warn "Source file missing: src/$f"
    fi
done

# Re-establish forced directory mappings (Symlinks are fine for directories)
rm -rf assets includes scripts
ln -sf src/assets assets
ln -sf src/includes includes
ln -sf src/scripts scripts
log_ok "Root entry points and directory mapping complete."

# Cache Reset
/usr/bin/php -r "if(function_exists('opcache_reset')) opcache_reset();" >/dev/null 2>&1

cd - > /dev/null

# -----------------------------------------------------------------
#  [3/5]  RUNTIME DEPENDENCIES
# -----------------------------------------------------------------
log_progress "40"
log_step "[3/5] Checking Runtime Tools..."

if [ -f "/tmp/aicli-runtime.sh" ]; then
    bash /tmp/aicli-runtime.sh
else
    log_warn "Runtime script (aicli-runtime.sh) missing. Tools may be unavailable."
fi

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
