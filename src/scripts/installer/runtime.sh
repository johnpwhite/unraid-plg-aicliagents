#!/bin/bash
_INSTALLER_RESOLVER="${AICLI_STAGED_SRC:-}/scripts/storage/resolve_paths.sh"
[ -f "$_INSTALLER_RESOLVER" ] || _INSTALLER_RESOLVER="$(dirname "${BASH_SOURCE[0]}")/../storage/resolve_paths.sh"
[ -f "$_INSTALLER_RESOLVER" ] || _INSTALLER_RESOLVER="${EMHTTP_DEST:-/usr/local/emhttp/plugins/unraid-aicliagents}/src/scripts/storage/resolve_paths.sh"
source "$_INSTALLER_RESOLVER" || { echo 'resolve_paths.sh missing' >&2; exit 1; }
# AICliAgents Installer: Runtime Dependencies (Node, tmux, fd, rg)

# Forgejo #292 (2026-09-23): any human-readable line this script prints must go
# through log_status (or log_step/log_ok/log_warn/log_fail), never a raw
# "echo ... >&3". FD 3 is the installer's numeric PROGRESS channel (see the
# I/O Setup block in the parent .plg), not a text channel, and on a real
# install it can go away mid-run (the browser tab drops the connection, a
# proxy times out). An unguarded write to a dead FD 3 fails loudly ("write
# error: Transport endpoint is not connected") and, worse, never reaches the
# user, so the install looked frozen even though it kept going and finished.
# log_status writes to FD 4 (the UI channel) AND the log file, and its FD 4
# write is guarded, so a dead descriptor is silent there and the message still
# lands in install.log. v2026.04.10.23 fixed two such raw "echo >&3" lines
# (the squashfs-tools download and extract lines, though it dropped them to
# ">&3"-free bare echoes rather than log_status, so they still never reached
# the UI). Three more raw FD 3 writes were missed that day — Node.js, tmux,
# and the shared install_tool helper used for fd/rg/git-lfs — and kept
# failing for five months until this fix.
# Do not reintroduce a raw ">&3" here.

# D-170: Move Runtime to plugin root (Flash/RAM) instead of Btrfs agents image.
# This ensures that if the agent image is corrupted or being replaced during a repair,
# the core system tools (Node, tmux, etc.) remain available to the repair engine.
RUNTIME_BASE="${AICLI_RUNTIME_BASE:-$EMHTTP_DEST/.runtime}"
BIN_DEST="${AICLI_BIN_DEST:-$EMHTTP_DEST/bin}"
GLOBAL_BIN_DIR="${AICLI_GLOBAL_BIN_DIR:-/usr/local/bin}"
mkdir -p "$RUNTIME_BASE"
mkdir -p "$BIN_DEST"
mkdir -p "$GLOBAL_BIN_DIR"

RUNTIME_WORK=$(mktemp -d "$RUNTIME_BASE/.provision.XXXXXX") || {
    log_fail "Could not create the runtime staging directory: $RUNTIME_BASE"
    exit 1
}
RUNTIME_ARCHIVE_PARTIAL=""
cleanup_runtime_work() {
    [ -z "${RUNTIME_ARCHIVE_PARTIAL:-}" ] || rm -f -- "$RUNTIME_ARCHIVE_PARTIAL"
    rm -rf -- "$RUNTIME_WORK"
}
trap cleanup_runtime_work EXIT
exec 8>"$RUNTIME_BASE/.provision.lock" || {
    log_fail "Could not open the runtime provisioning lock."
    exit 1
}
flock -w 60 8 || {
    log_fail "Runtime provisioning is already running."
    exit 1
}

# WP #1082 (2026-05-24): SHA256 verification for every download. Pinned URLs
# return the same bytes per upstream contract; a mismatch means the download
# was corrupted, MITM'd, or upstream re-published. Fail closed — refuse to
# install a binary we can't verify. Computed once by fetching from upstream;
# update via tools/refresh-runtime-sha256.sh if a URL is changed.
#
# verify_sha256 <file_path> <expected_sha256> <name>
# Returns 0 on a non-empty file with a matching checksum.
verify_sha256() {
    local file="$1" expected="${2:-}" name="${3:-binary}" actual bytes
    bytes=0
    [ ! -f "$file" ] || { bytes=$(wc -c < "$file" 2>/dev/null) || bytes=0; }
    if [ ! -s "$file" ]; then
        log_fail "$name: download missing or empty at $file (bytes=$bytes)."
        return 1
    fi
    [ -n "$expected" ] || return 0
    actual=$(sha256sum "$file" 2>/dev/null | awk '{print $1}') || actual=""
    if [ -z "$actual" ]; then
        log_fail "$name: sha256sum failed on $file."
        return 1
    fi
    if [ "$actual" != "$expected" ]; then
        log_fail "$name: SHA256 mismatch — expected=$expected actual=$actual (possible corruption / MITM)."
        return 1
    fi
    return 0
}

# download_verified <cache_path> <sha256-or-empty> <name> <url> [alternate_url...]
# Keep the old cache until a complete, non-empty candidate passes verification.
download_verified() {
    local dest="$1" expected="$2" name="$3" url attempt rc part publish_partial bytes
    shift 3
    publish_partial="${dest}.partial"
    RUNTIME_ARCHIVE_PARTIAL="$publish_partial"
    if [ -e "$dest" ]; then
        if verify_sha256 "$dest" "$expected" "$name"; then return 0; fi
        log_warn "$name: cached download failed verification; trying a fresh download."
    fi

    mkdir -p "$(dirname "$dest")" || return 1
    if ! rm -f -- "$publish_partial"; then
        log_fail "$name: could not clear the previous cache staging file: $publish_partial."
        return 1
    fi
    part=$(mktemp "$RUNTIME_WORK/download.XXXXXX") || return 1
    for url in "$@"; do
        for attempt in 1 2 3; do
            : > "$part"
            rc=0
            wget -q --timeout=20 --tries=1 -O "$part" "$url" || rc=$?
            bytes=$(wc -c < "$part" 2>/dev/null) || bytes=0
            log_status "$name: download attempt=$attempt rc=$rc url=$url bytes=$bytes"
            if [ "$rc" -eq 0 ] && verify_sha256 "$part" "$expected" "$name"; then
                if ! cp "$part" "$publish_partial"; then
                    log_fail "$name: could not stage the verified download on the cache filesystem."
                    rm -f -- "$part" "$publish_partial"
                    return 1
                fi
                if ! verify_sha256 "$publish_partial" "$expected" "$name"; then
                    rm -f -- "$part" "$publish_partial"
                    return 1
                fi
                if mv -f "$publish_partial" "$dest"; then
                    rm -f -- "$part"
                    return 0
                fi
                log_fail "$name: could not publish the verified download to $dest."
                rm -f -- "$part" "$publish_partial"
                return 1
            fi
            [ "$attempt" -eq 3 ] || sleep "$attempt"
        done
    done
    rm -f "$part"
    log_fail "$name: all verified download attempts failed; runtime unavailable."
    return 1
}

# binary_version_ok <executable> <version-flag> [tool-name]
# True when the executable runs and answers its version flag. squashfs-tools
# 4.5 prints its version and then exits 1 for "unsquashfs -version", so for
# the two squashfs tools exit 1 counts only with the real version line.
# A loader error ("version GLIBC_x not found") never matches that line.
# tool-name defaults to the file name; pass it for a temporary candidate file.
binary_version_ok() {
    local exe="$1" flag="$2" name="${3:-$(basename "$1")}" out rc
    out=$(timeout 5 "$exe" "$flag" 2>&1); rc=$?
    [ "$rc" -eq 0 ] && return 0
    case "$name:$flag:$rc" in
        mksquashfs:-version:1|unsquashfs:-version:1)
            printf '%s\n' "$out" | head -n 1 | grep -Eq "^$name version [0-9]" ;;
        *) return 1 ;;
    esac
}

# publish_binary <source> <destination> <version-flag>
# Validate a staged executable before an atomic rename replaces the destination.
publish_binary() {
    local source="$1" dest="$2" flag="$3" candidate
    if [ ! -s "$source" ] || [ ! -x "$source" ] || ! binary_version_ok "$source" "$flag" "$(basename "$dest")"; then
        log_fail "Executable validation failed for candidate: $source"
        return 1
    fi
    mkdir -p "$(dirname "$dest")" || return 1
    candidate=$(mktemp "$dest.next.XXXXXX") || return 1
    if ! cp "$source" "$candidate" || ! chmod 755 "$candidate" \
        || ! binary_version_ok "$candidate" "$flag" "$(basename "$dest")"; then
        rm -f "$candidate"
        log_fail "Executable validation failed before publication: $dest"
        return 1
    fi
    if ! mv -f "$candidate" "$dest"; then
        rm -f "$candidate"
        log_fail "Could not publish the validated executable: $dest"
        return 1
    fi
    return 0
}

# runtime_executable_works <bin_dir> <executable> <version_flag>
# npm and npx use /usr/bin/env node, so test them with the matching Node first.
runtime_executable_works() {
    local bin_dir="$1" executable="$2" flag="$3"
    PATH="$bin_dir:$PATH" binary_version_ok "$executable" "$flag"
}

# cleanup_stale_runtime_archives <cache_dir> <current_archive...>
# Keep only the current runtime archives for offline repair and reboot.
cleanup_stale_runtime_archives() {
    local cache_dir="$1" pattern file filename keep current current_cached
    shift
    for pattern in \
        'node-v*-linux-x64.tar.gz' \
        'tmux-v*.gz' \
        'fd-v*-x86_64-unknown-linux-musl.tar.gz' \
        'ripgrep-*-x86_64-unknown-linux-musl.tar.gz' \
        'git-lfs-linux-amd64-v*.tar.gz' \
        'squashfs-tools-*.txz'; do
        current_cached=0
        for keep in "$@"; do
            case "$keep" in
                $pattern) [ -f "$cache_dir/$keep" ] && current_cached=1 ;;
            esac
        done
        if [ "$current_cached" -ne 1 ]; then
            log_status "Keeping cached $pattern archives because the current archive is unavailable."
            continue
        fi
        for file in "$cache_dir"/$pattern; do
            [ -f "$file" ] || continue
            filename=${file##*/}
            current=0
            for keep in "$@"; do
                if [ "$filename" = "$keep" ]; then current=1; break; fi
            done
            if [ "$current" -eq 0 ]; then
                log_status "Removing stale runtime archive $filename."
                rm -f -- "$file" || log_warn "Could not remove stale runtime archive $filename."
            fi
        done
    done
}

# --- 1. Node.js Runtime (v22+ required) ---
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"
NODE_SHA256="c33c39ed9c80deddde77c960d00119918b9e352426fd604ba41638d6526a4744"

USE_SYSTEM_NODE=1
REAL_NODE=""
REAL_NPM=""
REAL_NPX=""
for node_tool in node npm npx; do
    found=$(command -v "$node_tool" 2>/dev/null || true)
    real=$(readlink -f "$found" 2>/dev/null || true)
    case "$real" in
        ""|"$BIN_DEST"/*|"$RUNTIME_BASE"/*) USE_SYSTEM_NODE=0 ;;
    esac
    if [ -z "$real" ] || [ ! -x "$real" ] \
        || ! runtime_executable_works "$(dirname "$found")" "$found" --version; then
        USE_SYSTEM_NODE=0
    fi
    case "$node_tool" in
        node) REAL_NODE="$real" ;;
        npm) REAL_NPM="$real" ;;
        npx) REAL_NPX="$real" ;;
    esac
done
NODE_VER=$("${REAL_NODE:-/nonexistent}" -v 2>/dev/null | sed 's/v//' | cut -d. -f1) || NODE_VER=0
if ! [[ "$NODE_VER" =~ ^[0-9]+$ ]] || [ "$NODE_VER" -lt 22 ]; then USE_SYSTEM_NODE=0; fi

log_step "Node.js runtime..."
if [ "$USE_SYSTEM_NODE" -eq 1 ]; then
    log_ok "System Node.js v$NODE_VER -- using system runtime."
    ln -sfn "$REAL_NODE" "$BIN_DEST/node"
    ln -sfn "$REAL_NPM" "$BIN_DEST/npm"
    ln -sfn "$REAL_NPX" "$BIN_DEST/npx"
else
    PORTABLE_NODE_READY=1
    for node_tool in node npm npx; do
        if [ ! -x "$RUNTIME_BASE/node/bin/$node_tool" ] \
            || ! runtime_executable_works "$RUNTIME_BASE/node/bin" "$RUNTIME_BASE/node/bin/$node_tool" --version; then
            PORTABLE_NODE_READY=0
        fi
    done
    if [ "$PORTABLE_NODE_READY" -ne 1 ]; then
        log_status "    > Downloading portable Node.js..."
        download_verified "$CONFIG_DIR/$NODE_TAR" "$NODE_SHA256" "Node.js" "$NODE_URL" || exit 1
        TMP_EXTRACT="$RUNTIME_WORK/node-extract"
        mkdir -p "$TMP_EXTRACT" || exit 1
        if ! tar -xzf "$CONFIG_DIR/$NODE_TAR" -C "$TMP_EXTRACT" --no-same-owner; then
            log_fail "Node.js archive extraction failed."
            exit 1
        fi
        NODE_DIR=$(find "$TMP_EXTRACT" -maxdepth 1 -mindepth 1 -type d -name "node-*" | head -n 1)
        if [ -z "$NODE_DIR" ] || [ ! -d "$NODE_DIR/bin" ]; then
            log_fail "Node.js archive has no runtime directory."
            exit 1
        fi
        NODE_CANDIDATE="$RUNTIME_WORK/node-candidate"
        mkdir -p "$NODE_CANDIDATE" || exit 1
        if ! cp -R "$NODE_DIR/." "$NODE_CANDIDATE/" || ! chmod +x "$NODE_CANDIDATE/bin/"*; then
            log_fail "Node.js candidate staging failed."
            exit 1
        fi
        for node_tool in node npm npx; do
            if [ ! -x "$NODE_CANDIDATE/bin/$node_tool" ] \
                || ! runtime_executable_works "$NODE_CANDIDATE/bin" "$NODE_CANDIDATE/bin/$node_tool" --version; then
                log_fail "Portable Node.js candidate failed validation: $node_tool"
                exit 1
            fi
        done
        NODE_OLD="$RUNTIME_WORK/node-old"
        if [ -e "$RUNTIME_BASE/node" ] || [ -L "$RUNTIME_BASE/node" ]; then
            mv "$RUNTIME_BASE/node" "$NODE_OLD" || { log_fail "Could not preserve the old Node.js runtime."; exit 1; }
        fi
        if ! mv "$NODE_CANDIDATE" "$RUNTIME_BASE/node"; then
            [ ! -e "$NODE_OLD" ] || mv "$NODE_OLD" "$RUNTIME_BASE/node" 2>/dev/null || true
            log_fail "Could not publish the validated Node.js runtime."
            exit 1
        fi
        log_ok "Portable Node.js installed to plugin runtime."
    else
        log_ok "Portable Node.js found in plugin runtime."
    fi
    ln -sf "$RUNTIME_BASE/node/bin/node" "$BIN_DEST/node"
    ln -sf "$RUNTIME_BASE/node/bin/npm" "$BIN_DEST/npm"
    ln -sf "$RUNTIME_BASE/node/bin/npx" "$BIN_DEST/npx"
fi

# Global npm/npx symlinks
[ -x "$GLOBAL_BIN_DIR/npm" ] || ln -sfn "$BIN_DEST/npm" "$GLOBAL_BIN_DIR/npm"
[ -x "$GLOBAL_BIN_DIR/npx" ] || ln -sfn "$BIN_DEST/npx" "$GLOBAL_BIN_DIR/npx"

# --- 2. tmux (v3.6a) ---
TMUX_TAR="tmux-v3.6a.gz"
TMUX_URL="https://github.com/mjakob-gh/build-static-tmux/releases/download/v3.6a/tmux.linux-amd64.gz"
TMUX_SHA256="e6e1b346af08e17f47b66571a50fa76ed499e6a1cd000519f5f9e04c4722b7be"

log_step "tmux..."
TMUX_FOUND=$(command -v tmux 2>/dev/null || true)
REAL_TMUX=$(readlink -f "$TMUX_FOUND" 2>/dev/null || true)
case "$REAL_TMUX" in
    "$BIN_DEST"/*|"$RUNTIME_BASE"/*) REAL_TMUX="" ;;
esac
if [ -n "$REAL_TMUX" ] && [ -x "$REAL_TMUX" ] && timeout 5 "$REAL_TMUX" -V >/dev/null 2>&1; then
    log_ok "System tmux found at $REAL_TMUX."
    ln -sfn "$REAL_TMUX" "$BIN_DEST/tmux"
else
    if [ ! -x "$RUNTIME_BASE/bin/tmux" ] || ! timeout 5 "$RUNTIME_BASE/bin/tmux" -V >/dev/null 2>&1; then
        log_status "    > Downloading portable tmux..."
        download_verified "$CONFIG_DIR/$TMUX_TAR" "$TMUX_SHA256" "tmux" "$TMUX_URL" || exit 1
        if ! gunzip -c "$CONFIG_DIR/$TMUX_TAR" > "$RUNTIME_WORK/tmux"; then
            log_fail "tmux archive extraction failed."
            exit 1
        fi
        chmod +x "$RUNTIME_WORK/tmux" || { log_fail "Could not mark the tmux candidate executable."; exit 1; }
        publish_binary "$RUNTIME_WORK/tmux" "$RUNTIME_BASE/bin/tmux" -V || exit 1
        log_ok "Portable tmux installed."
    else
        log_ok "Portable tmux found in plugin root."
    fi
    ln -sfn "$RUNTIME_BASE/bin/tmux" "$BIN_DEST/tmux"
fi
if [ ! -x "$GLOBAL_BIN_DIR/tmux" ]; then
    ln -sfn "$BIN_DEST/tmux" "$GLOBAL_BIN_DIR/tmux"
fi
if ! timeout 5 "$BIN_DEST/tmux" -V >/dev/null 2>&1; then
    log_fail "Required tmux runtime failed its executable check."
    exit 1
fi

# --- 3. fd and ripgrep ---
FD_TAR="fd-v10.3.0-x86_64-unknown-linux-musl.tar.gz"
FD_URL="https://github.com/sharkdp/fd/releases/download/v10.3.0/$FD_TAR"
FD_SHA256="2b6bfaae8c48f12050813c2ffe1884c61ea26e750d803df9c9114550a314cd14"
RG_TAR="ripgrep-14.1.0-x86_64-unknown-linux-musl.tar.gz"
RG_URL="https://github.com/BurntSushi/ripgrep/releases/download/14.1.0/$RG_TAR"
RG_SHA256="f84757b07f425fe5cf11d87df6644691c644a5cd2348a2c670894272999d3ba7"
GIT_LFS_TAR="git-lfs-linux-amd64-v3.7.0.tar.gz"
GIT_LFS_URL="https://github.com/git-lfs/git-lfs/releases/download/v3.7.0/$GIT_LFS_TAR"
GIT_LFS_SHA256="e7ebba491af8a54e560be3a00666fa97e4cf2bbbb223178a0934b8ef74cf9bed"

# Resolve a tool the plugin ships, without ever adopting our own shim.
#
# 2026-09-09 (reported by a workspace whose LFS commits silently dropped large
# files): this function built a symlink LOOP on every install after the first.
#
#   first install : /usr/local/bin/<n> -> $BIN_DEST/<n> -> $RUNTIME_BASE/bin/<n>   correct
#   second install: `command -v <n>` now finds OUR OWN /usr/local/bin/<n>, so the
#                   old code did `ln -sf $(which <n>) $BIN_DEST/<n>`, pointing
#                   $BIN_DEST/<n> back at /usr/local/bin/<n>, which already points
#                   at $BIN_DEST/<n>. The loop closes and the tool disappears from
#                   PATH entirely ("Too many levels of symbolic links").
#
# git-lfs, fd and rg were all circular on the reporting box. git-lfs is the one that
# loses data: `git-lfs filter-process` is not found, `git add` fails its clean filter,
# and the commit still succeeds for everything else — so large files are omitted from
# a commit that looks clean.
#
# Adopt only executable system tools outside the plugin's own trees. Stage and
# validate portable candidates before publication. Leave working system tools
# in place when their global command already works.
install_tool() {
    local tar=$1 url=$2 name=$3 expected_sha=$4
    log_step "$name..."

    local found real="" tmp bin pub
    found=$(command -v "$name" 2>/dev/null || true)
    [ -n "$found" ] && real=$(readlink -f "$found" 2>/dev/null || true)
    case "$real" in
        ""|"$BIN_DEST"/*|"$RUNTIME_BASE"/bin/*) real="" ;;   # our own shim, or already looping
    esac

    if [ -n "$real" ] && [ -x "$real" ] && timeout 5 "$real" --version >/dev/null 2>&1; then
        log_ok "System $name found."
        ln -sfn "$real" "$BIN_DEST/$name" || return 1
    else
        if [ ! -x "$RUNTIME_BASE/bin/$name" ] || ! timeout 5 "$RUNTIME_BASE/bin/$name" --version >/dev/null 2>&1; then
            log_status "    > Downloading portable $name..."
            download_verified "$CONFIG_DIR/$tar" "$expected_sha" "$name" "$url" || return 1
            tmp=$(mktemp -d "$RUNTIME_WORK/$name-extract.XXXXXX") || return 1
            if ! tar -xf "$CONFIG_DIR/$tar" -C "$tmp" --no-same-owner; then
                rm -rf "$tmp"
                log_fail "$name archive extraction failed."
                return 1
            fi
            bin=$(find "$tmp" -name "$name" -type f -executable | head -n 1)
            if [ -z "$bin" ]; then
                rm -rf "$tmp"
                log_fail "$name binary is missing from its archive."
                return 1
            fi
            if ! publish_binary "$bin" "$RUNTIME_BASE/bin/$name" --version; then
                rm -rf "$tmp"
                return 1
            fi
            rm -rf "$tmp"
            log_ok "Portable $name installed."
        else
            log_ok "$name found in plugin root."
        fi
        ln -sfn "$RUNTIME_BASE/bin/$name" "$BIN_DEST/$name" || return 1
    fi

    pub="$GLOBAL_BIN_DIR/$name"
    if [ ! -x "$pub" ]; then
        ln -sfn "$BIN_DEST/$name" "$pub" || return 1
    fi
    if ! timeout 5 "$BIN_DEST/$name" --version >/dev/null 2>&1; then
        log_fail "$name runtime failed its executable check."
        return 1
    fi
    return 0
}

# fd and rg are optional conveniences. Their callers run in subshells and log
# a warning when installation fails. git-lfs remains required because a broken
# LFS filter can omit large files from a successful commit.
( install_tool "$FD_TAR" "$FD_URL" "fd" "$FD_SHA256" ) \
    || log_warn "fd install failed — continuing without it (fd is an optional convenience tool)."
( install_tool "$RG_TAR" "$RG_URL" "rg" "$RG_SHA256" ) \
    || log_warn "rg install failed — continuing without it (rg is an optional convenience tool)."
install_tool "$GIT_LFS_TAR" "$GIT_LFS_URL" "git-lfs" "$GIT_LFS_SHA256" || exit 1

# --- 4. squashfs-tools (mksquashfs/unsquashfs) ---
# D-304: Ensure SquashFS tools are available for the new storage architecture.
# UPDATED: Slackware 15.0 moved tools to 'ap' series and updated to -2 rebuild.
SQUASH_TAR="squashfs-tools-4.5-x86_64-2.txz"
SQUASH_URL="https://mirrors.slackware.com/slackware/slackware64-15.0/slackware64/ap/$SQUASH_TAR"
SQUASH_URL_ALT="https://slackware.uk/slackware/slackware64-15.0/slackware64/ap/$SQUASH_TAR"
SQUASH_SHA256="1615c7198fe92513fb47326c53a52031448dc9c2b7858382a0d2a1d39bd95b45"

log_step "squashfs-tools..."

# D-307: Prevent circular symlinks. Resolve command names before deciding
# whether a binary is a system tool or one of our own plugin links.
SYS_MKSQUASHFS=$(readlink -f "$(command -v mksquashfs 2>/dev/null || true)" 2>/dev/null || true)
SYS_UNSQUASHFS=$(readlink -f "$(command -v unsquashfs 2>/dev/null || true)" 2>/dev/null || true)
IS_SYSTEM=0
case "$SYS_MKSQUASHFS:$SYS_UNSQUASHFS" in
    *"$BIN_DEST"*|*"$RUNTIME_BASE"*) ;;
    *)
        if [ -x "$SYS_MKSQUASHFS" ] && [ -x "$SYS_UNSQUASHFS" ] \
            && binary_version_ok "$SYS_MKSQUASHFS" -version mksquashfs \
            && binary_version_ok "$SYS_UNSQUASHFS" -version unsquashfs; then
            IS_SYSTEM=1
        fi
        ;;
esac

PORTABLE_SQUASH_READY=1
for squash_tool in mksquashfs unsquashfs; do
    if [ ! -x "$RUNTIME_BASE/bin/$squash_tool" ] \
        || ! binary_version_ok "$RUNTIME_BASE/bin/$squash_tool" -version; then
        PORTABLE_SQUASH_READY=0
    fi
done

if [ "$IS_SYSTEM" -eq 1 ]; then
    log_ok "System squashfs-tools found."
    ln -sfn "$SYS_MKSQUASHFS" "$BIN_DEST/mksquashfs"
    ln -sfn "$SYS_UNSQUASHFS" "$BIN_DEST/unsquashfs"
else
    if [ "$PORTABLE_SQUASH_READY" -ne 1 ]; then
        download_verified "$CONFIG_DIR/$SQUASH_TAR" "$SQUASH_SHA256" "squashfs-tools" "$SQUASH_URL" "$SQUASH_URL_ALT" || exit 1
        TMP_EXTRACT="$RUNTIME_WORK/squash-extract"
        mkdir -p "$TMP_EXTRACT" || exit 1
        if ! tar -xf "$CONFIG_DIR/$SQUASH_TAR" -C "$TMP_EXTRACT" --no-same-owner; then
            log_fail "Extraction of squashfs-tools failed. The download may be corrupted."
            exit 1
        fi
        SQUASH_MKS="$TMP_EXTRACT/usr/bin/mksquashfs"
        SQUASH_UNS="$TMP_EXTRACT/usr/bin/unsquashfs"
        if [ ! -s "$SQUASH_MKS" ] || [ ! -s "$SQUASH_UNS" ]; then
            log_fail "mksquashfs or unsquashfs is missing from the extracted package."
            exit 1
        fi
        chmod +x "$SQUASH_MKS" "$SQUASH_UNS" || exit 1
        if ! binary_version_ok "$SQUASH_MKS" -version \
            || ! binary_version_ok "$SQUASH_UNS" -version; then
            log_fail "Extracted squashfs-tools failed executable checks."
            exit 1
        fi
        publish_binary "$SQUASH_MKS" "$RUNTIME_BASE/bin/mksquashfs" -version || exit 1
        publish_binary "$SQUASH_UNS" "$RUNTIME_BASE/bin/unsquashfs" -version || exit 1
        log_ok "Portable squashfs-tools installed."
    else
        log_ok "Portable squashfs-tools found in plugin runtime."
    fi
    ln -sfn "$RUNTIME_BASE/bin/mksquashfs" "$BIN_DEST/mksquashfs"
    ln -sfn "$RUNTIME_BASE/bin/unsquashfs" "$BIN_DEST/unsquashfs"
fi

if [ ! -x "$GLOBAL_BIN_DIR/mksquashfs" ]; then
    ln -sfn "$BIN_DEST/mksquashfs" "$GLOBAL_BIN_DIR/mksquashfs"
fi
if [ ! -x "$GLOBAL_BIN_DIR/unsquashfs" ]; then
    ln -sfn "$BIN_DEST/unsquashfs" "$GLOBAL_BIN_DIR/unsquashfs"
fi

# Required runtime programs must execute through the paths that the plugin uses.
for runtime_spec in "node:--version" "npm:--version" "npx:--version" "tmux:-V" "git-lfs:--version" "mksquashfs:-version" "unsquashfs:-version"; do
    runtime_tool=${runtime_spec%%:*}
    runtime_flag=${runtime_spec#*:}
    if ! runtime_executable_works "$BIN_DEST" "$BIN_DEST/$runtime_tool" "$runtime_flag"; then
        log_fail "Required runtime check failed: $runtime_tool $runtime_flag."
        exit 1
    fi
done

TTYD_BIN=$(command -v ttyd 2>/dev/null || true)
if [ -z "$TTYD_BIN" ] && [ -x /usr/bin/ttyd ]; then TTYD_BIN=/usr/bin/ttyd; fi
if [ -z "$TTYD_BIN" ] || [ ! -x "$TTYD_BIN" ]; then
    log_fail "Required runtime ttyd is missing or not executable."
    exit 1
fi

cleanup_stale_runtime_archives "$CONFIG_DIR" "$NODE_TAR" "$TMUX_TAR" "$FD_TAR" "$RG_TAR" "$GIT_LFS_TAR" "$SQUASH_TAR"

# --- 5. Agent Proxy Wrappers ---
# D-346: Create robust proxy scripts in BIN_DEST that point to the actual entry points.
# This bypasses broken NPM symlinks in SquashFS and ensures they are in the PATH.
log_step "Agent proxy wrappers..."

create_proxy() {
    local cmd=$1 pkg_path=$2
    local wrapper="$BIN_DEST/$cmd"
    local wrapper_tmp="$wrapper.tmp.$$"
    local target="$EMHTTP_DEST/agents/$pkg_path"
    local bin_dest="$BIN_DEST"

    cat <<'PROXYEOF' | sed -e "s|__BIN_DEST__|${bin_dest}|g" -e "s|__TARGET__|${target}|g" -e "s|__CMD__|${cmd}|g" -e "s|__PKG_PATH__|${pkg_path}|g" > "$wrapper_tmp"
#!/bin/bash
# AICliAgents Proxy Wrapper for __CMD__
# The entry point is baked at plugin-install time, but npm packages occasionally
# restructure (e.g. claude-code 2.1.x dropped cli.js for a native bin/claude.exe).
# If the baked path is gone, re-resolve it from the package's package.json "bin"
# field at runtime so the wrapper self-heals across in-place agent upgrades — Bug #761.
export PATH="__BIN_DEST__:$PATH"
NODE="__BIN_DEST__/node"
TARGET="__TARGET__"

if [ ! -e "$TARGET" ]; then
    d=$(dirname "$TARGET")
    while [ -n "$d" ] && [ "$d" != "/" ] && [ "$d" != "." ]; do
        [ "$(basename "$d")" = "node_modules" ] && break
        if [ -f "$d/package.json" ]; then
            entry=$("$NODE" -e 'try{var b=require(process.argv[1]+"/package.json").bin;process.stdout.write(typeof b==="string"?b:((b&&(b[process.argv[2]]||Object.values(b)[0]))||""))}catch(e){}' "$d" "__CMD__" 2>/dev/null)
            [ -n "$entry" ] && [ -e "$d/$entry" ] && TARGET="$d/$entry"
            break
        fi
        d=$(dirname "$d")
    done
fi

if [ "${TARGET%.js}" != "$TARGET" ] && [ -e "$TARGET" ]; then
    # Direct JS entry point
    "$NODE" "$TARGET" "$@"
elif [ -f "$TARGET" ] && head -c 4 "$TARGET" 2>/dev/null | grep -q 'ELF'; then
    # ELF binary
    "$TARGET" "$@"
elif [ -e "$TARGET" ]; then
    # Script or symlink - try native execution first, fallback to node
    if ! "$TARGET" "$@" 2>/dev/null; then
        "$NODE" "$TARGET" "$@"
    fi
else
    echo "AICliAgents: '__CMD__' entry point not found at $TARGET — is the agent installed?" >&2
    exit 127
fi
PROXYEOF
    chmod +x "$wrapper_tmp"
    mv -f "$wrapper_tmp" "$wrapper"
    ln -sfn "$wrapper" "$GLOBAL_BIN_DIR/$cmd"
}

# Proxy mapping: cmd -> relative path from /agents/ to entry
create_proxy "gemini" "gemini-cli/node_modules/@google/gemini-cli/bundle/gemini.js"
create_proxy "copilot" "gh-copilot/node_modules/@github/copilot/index.js"
# claude-code 2.1.x ships the CLI as a native ELF at bin/claude.exe (older 2.0.x had
# cli.js at the package root — the wrapper's self-heal block falls back to whatever
# package.json "bin" points at if this path is ever absent). Bug #761.
create_proxy "claude" "claude-code/node_modules/@anthropic-ai/claude-code/bin/claude.exe"
create_proxy "opencode" "opencode/node_modules/opencode-ai/bin/opencode"
create_proxy "opencode2" "opencode2/node_modules/@opencode/cli/bin/opencode.exe"
create_proxy "kilo" "kilocode/node_modules/@kilocode/cli/bin/kilo"
create_proxy "pi" "pi-coder/node_modules/@mariozechner/pi-coding-agent/dist/cli.js"
create_proxy "codex" "codex-cli/node_modules/.bin/codex"
create_proxy "droid" "factory-cli/node_modules/.bin/droid"
create_proxy "nanocoder" "nanocoder/node_modules/.bin/nanocoder"
create_proxy "goose" "goose/bin/goose"
create_proxy "qwen" "qwen-code/node_modules/@qwen-code/qwen-code/cli.js"
create_proxy "agy"  "antigravity-cli/home/.local/bin/agy"
create_proxy "grok" "grok-build/home/.grok/bin/grok"
create_proxy "kimi" "kimi-code/home/.kimi-code/bin/kimi"

log_ok "Agent proxies established (gemini, copilot, claude, opencode, kilo, pi, codex, droid, nanocoder, goose, qwen, agy, grok, kimi)."

# --- 6. Docker Tool Wrappers ---
# Tools that require Docker containers. The wrapper either proxies to the container
# or returns a helpful error message telling the user/agent how to install it.
log_step "Docker tool wrappers..."

create_docker_proxy() {
    local cmd=$1 image=$2 docker_cmd=$3 description=$4
    local wrapper="$BIN_DEST/$cmd"
    local wrapper_tmp="$wrapper.tmp.$$"
    local log_file="${MOUNT_ROOT}/debug.log"

    cat > "$wrapper_tmp" <<'DOCKEREOF'
#!/bin/bash
# AICliAgents Docker Proxy: __CMD__
CMD="__CMD__"
IMAGE="__IMAGE__"
DOCKER_CMD="__DOCKER_CMD__"
DESCRIPTION="__DESCRIPTION__"
source "/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/resolve_paths.sh" || exit 1
LOG="${MOUNT_ROOT}/debug.log"

log_proxy() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] [DockerProxy] $1" >> "$LOG" 2>/dev/null
}

log_proxy "$CMD invoked with args: $*"

if ! command -v docker >/dev/null 2>&1; then
    MSG="$CMD requires Docker, which is not available on this system. To use $CMD: 1) Enable Docker in Unraid Settings > Docker. 2) Pull the image: docker pull $IMAGE"
    log_proxy "BLOCKED: Docker not available"
    echo "$MSG" >&2
    exit 1
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    MSG="$CMD requires the Docker image '$IMAGE' which is not yet pulled. To install, run: docker pull $IMAGE — or search '$CMD' in Unraid's Apps tab. $DESCRIPTION"
    log_proxy "BLOCKED: Docker image $IMAGE not found"
    echo "$MSG" >&2
    exit 1
fi

log_proxy "Executing via Docker: $IMAGE $DOCKER_CMD $*"
exec docker run --rm -v "$(pwd)":/src -w /src "$IMAGE" $DOCKER_CMD "$@"
DOCKEREOF

    # Replace placeholders with actual values
    sed -i "s|__CMD__|${cmd}|g" "$wrapper_tmp"
    sed -i "s|__IMAGE__|${image}|g" "$wrapper_tmp"
    sed -i "s|__DOCKER_CMD__|${docker_cmd}|g" "$wrapper_tmp"
    sed -i "s|__DESCRIPTION__|${description}|g" "$wrapper_tmp"

    chmod +x "$wrapper_tmp"
    mv -f "$wrapper_tmp" "$wrapper"
    ln -sfn "$wrapper" "$GLOBAL_BIN_DIR/$cmd"
}

create_docker_proxy "semgrep" "semgrep/semgrep" "semgrep" \
    "Semgrep is a static analysis tool for finding bugs and security issues. Used by Claude Code for Python linting."

DOCKER_TOOLS_MSG=""
if command -v docker >/dev/null 2>&1; then
    if docker image inspect "semgrep/semgrep" >/dev/null 2>&1; then
        log_ok "Docker tool wrappers ready (semgrep: container available)."
    else
        DOCKER_TOOLS_MSG="semgrep"
        log_ok "Docker tool wrappers ready (semgrep: wrapper installed, container not yet pulled)."
    fi
else
    DOCKER_TOOLS_MSG="semgrep"
    log_ok "Docker tool wrappers ready (semgrep: wrapper installed, Docker not running)."
fi

if [ -n "$DOCKER_TOOLS_MSG" ]; then
    log_status "  > NOTE: For full linting support, install the Docker container for: $DOCKER_TOOLS_MSG"
    log_status "  >   Pull manually: docker pull semgrep/semgrep"
    log_status "  >   Or search 'semgrep' in Unraid's Apps tab."
fi

# --- 7. Legacy Cleanup ---
# Remove old runtime artifacts from previous plugin architectures.
if [ -d "$EMHTTP_DEST/lib" ]; then
    log_warn "Removing legacy RAM libraries..."
    rm -rf "$EMHTTP_DEST/lib"
fi
if [ -d "$BIN_DEST/node_modules" ]; then
    log_warn "Removing legacy RAM node_modules..."
    rm -rf "$BIN_DEST/node_modules"
fi

log_ok "All runtime dependencies ready."
