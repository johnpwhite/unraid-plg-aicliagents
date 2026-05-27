#!/bin/bash
# AICliAgents Installer: UI & Static Assets (v41)
# Note: Payload is now primarily delivered via unified src.tar.gz extraction.

log_step "Verifying UI components and assets..."

# All assets are expected to be present after src.tar.gz extraction in the main engine.
# We perform a quick sanity check and set executable bits for scripts.

if [ -d "$EMHTTP_DEST/src/scripts" ]; then
    chmod +x "$EMHTTP_DEST"/src/scripts/*.sh 2>/dev/null || true
    chmod +x "$EMHTTP_DEST"/src/scripts/installer/*.sh 2>/dev/null || true
    chmod +x "$EMHTTP_DEST"/src/scripts/uninstaller/*.sh 2>/dev/null || true
    chmod +x "$EMHTTP_DEST"/src/scripts/user/*.sh 2>/dev/null || true
    log_ok "Script permissions verified."
else
    log_warn "Scripts directory (src/scripts) missing. Tarball extraction may have failed."
fi

# D-185: Ensure the uninstaller is available at the expected legacy path for Unraid
if [ -f "$EMHTTP_DEST/src/scripts/uninstaller/uninstall-engine.sh" ]; then
    ln -sf "src/scripts/uninstaller/uninstall-engine.sh" "$EMHTTP_DEST/scripts/uninstall.sh"
    chmod +x "$EMHTTP_DEST/src/scripts/uninstaller/uninstall-engine.sh"
    log_ok "Uninstaller symlink established."
fi

log_ok "UI components and assets verification complete."

