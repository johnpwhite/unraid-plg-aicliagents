#!/bin/bash
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
RUNTIME_BASE="$EMHTTP_DEST/.runtime"
BIN_DEST="$EMHTTP_DEST/bin"
mkdir -p "$RUNTIME_BASE"
mkdir -p "$BIN_DEST"

# WP #1082 (2026-05-24): SHA256 verification for every download. Pinned URLs
# return the same bytes per upstream contract; a mismatch means the download
# was corrupted, MITM'd, or upstream re-published. Fail closed — refuse to
# install a binary we can't verify. Computed once by fetching from upstream;
# update via tools/refresh-runtime-sha256.sh if a URL is changed.
#
# Runtime provisioning runs in its own shell, never sourced by the engine.
set -eE -o pipefail
trap 'rc=$?; log_fail "Runtime provisioning aborted: rc=$rc line=$LINENO"; exit "$rc"' ERR

# All scratch paths are private. Cached archives are published only after a
# successful transfer, nonempty check and pinned digest verification.
RUNTIME_WORK=$(mktemp -d "$RUNTIME_BASE/.provision.XXXXXX")
trap 'rm -rf -- "$RUNTIME_WORK"' EXIT
exec 8>"$RUNTIME_BASE/.provision.lock"
flock -w 60 8 || { log_fail "Runtime provisioning is already running."; exit 1; }

verify_sha256() {
    local file="$1" expected="$2" name="${3:-binary}" actual bytes
    bytes=$(wc -c < "$file" 2>/dev/null) || bytes=0
    if [ ! -s "$file" ]; then
        log_fail "$name: empty or missing download: path=$file bytes=$bytes"
        return 1
    fi
    actual=$(sha256sum "$file" | awk '{print $1}') || return 1
    if [ "$actual" != "$expected" ]; then
        log_fail "$name: SHA256 mismatch: path=$file bytes=$bytes expected=$expected actual=$actual"
        return 1
    fi
}

download_verified() {
    local dest="$1" expected="$2" name="$3" url attempt rc part
    shift 3
    if verify_sha256 "$dest" "$expected" "$name"; then return 0; fi
    part=$(mktemp "$RUNTIME_WORK/download.XXXXXX") || return 1
    for url in "$@"; do
        for attempt in 1 2 3; do
            rc=0
            # Do not hide stderr: DNS, HTTP and transfer errors belong in install.log.
            wget --timeout=20 --tries=1 -O "$part" "$url" || rc=$?
            log_status "$name: acquisition attempt=$attempt rc=$rc url=$url bytes=$(wc -c < "$part")"
            if [ "$rc" -eq 0 ] && verify_sha256 "$part" "$expected" "$name"; then
                # dest is in CONFIG_DIR; stage beside it for same-filesystem rename.
                local cached
                cached=$(mktemp "$dest.verified.XXXXXX") || { rm -f "$part"; return 1; }
                if cp "$part" "$cached" && mv -f "$cached" "$dest"; then
                    rm -f "$part"
                    return 0
                fi
                rm -f "$cached" "$part"
                return 1
            fi
            [ "$attempt" -eq 3 ] || sleep "$attempt"
        done
    done
    rm -f "$part"
    log_fail "$name: all verified acquisition attempts failed; runtime unavailable."
    return 1
}

# A validated executable replaces the destination with a single rename. Never
# truncate a usable binary, even when extraction or a smoke check fails.
publish_binary() {
    local source="$1" dest="$2" flag="$3" candidate
    mkdir -p "$(dirname "$dest")" || return 1
    candidate=$(mktemp "$dest.next.XXXXXX") || return 1
    if cp "$source" "$candidate" && chmod 755 "$candidate" &&
        timeout 5 "$candidate" "$flag" >/dev/null 2>&1 && mv -f "$candidate" "$dest"; then
        return 0
    fi
    rm -f "$candidate"
    log_fail "Executable validation/publication failed: $dest"
    return 1
}

# --- 1. Node.js Runtime (v22+ required) ---
NODE_TAR="node-v22.22.0-linux-x64.tar.gz"
NODE_URL="https://nodejs.org/dist/v22.22.0/node-v22.22.0-linux-x64.tar.gz"
NODE_SHA256="c33c39ed9c80deddde77c960d00119918b9e352426fd604ba41638d6526a4744"

USE_SYSTEM_NODE=1
for tool in node npm npx; do
    real=$(readlink -f "$(command -v "$tool" 2>/dev/null || true)" 2>/dev/null || true)
    case "$real" in
        ""|"$BIN_DEST"/*|"$RUNTIME_BASE"/*) USE_SYSTEM_NODE=0 ;;
    esac
    if [ -z "$real" ] || ! timeout 5 "$real" --version >/dev/null 2>&1; then
        USE_SYSTEM_NODE=0
    fi
    case "$tool" in node) REAL_NODE="$real" ;; npm) REAL_NPM="$real" ;; npx) REAL_NPX="$real" ;; esac
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
    if ! "$RUNTIME_BASE/node/bin/node" --version >/dev/null 2>&1; then
        log_status "    > Downloading portable Node.js..."
        download_verified "$CONFIG_DIR/$NODE_TAR" "$NODE_SHA256" "Node.js" "$NODE_URL" || exit 1
        TMP_EXTRACT="$RUNTIME_WORK/node"
        mkdir -p "$TMP_EXTRACT"
        tar -xf "$CONFIG_DIR/$NODE_TAR" -C "$TMP_EXTRACT" --no-same-owner
        NODE_DIR=$(find "$TMP_EXTRACT" -maxdepth 1 -mindepth 1 -type d -name "node-*" | head -n 1)
        NODE_CANDIDATE="$RUNTIME_WORK/node-candidate"
        mkdir -p "$NODE_CANDIDATE"
        cp -r "$NODE_DIR/"* "$NODE_CANDIDATE/"
        chmod +x "$NODE_CANDIDATE/bin/"*
        for tool in node npm npx; do
            PATH="$NODE_CANDIDATE/bin:$PATH" timeout 5 "$NODE_CANDIDATE/bin/$tool" --version >/dev/null 2>&1 || {
                log_fail "Portable Node.js candidate failed: $tool"
                exit 1
            }
        done
        # Only an unusable runtime reaches here. Keep it until the complete
        # replacement validates, and restore it if the publication fails.
        if [ -e "$RUNTIME_BASE/node" ]; then
            mv "$RUNTIME_BASE/node" "$RUNTIME_WORK/node-old"
        fi
        if ! mv "$NODE_CANDIDATE" "$RUNTIME_BASE/node"; then
            [ ! -e "$RUNTIME_WORK/node-old" ] || mv "$RUNTIME_WORK/node-old" "$RUNTIME_BASE/node"
            exit 1
        fi
        rm -rf "$TMP_EXTRACT"
        # Keep the verified archive for offline boot recovery.
        log_ok "Portable Node.js installed to plugin runtime."
    else
        log_ok "Portable Node.js found in plugin runtime."
    fi
    ln -sf "$RUNTIME_BASE/node/bin/node" "$BIN_DEST/node"
    ln -sf "$RUNTIME_BASE/node/bin/npm" "$BIN_DEST/npm"
    ln -sf "$RUNTIME_BASE/node/bin/npx" "$BIN_DEST/npx"
fi

# Global npm/npx symlinks
[ ! -e /usr/local/bin/npm ] && ln -sf "$BIN_DEST/npm" /usr/local/bin/npm
[ ! -e /usr/local/bin/npx ] && ln -sf "$BIN_DEST/npx" /usr/local/bin/npx

# --- 2. tmux (v3.6a) ---
TMUX_TAR="tmux-v3.6a.gz"
TMUX_URL="https://github.com/mjakob-gh/build-static-tmux/releases/download/v3.6a/tmux.linux-amd64.gz"
TMUX_SHA256="e6e1b346af08e17f47b66571a50fa76ed499e6a1cd000519f5f9e04c4722b7be"

log_step "tmux..."
# Resolve the real target before changing any link, avoiding circular shims
# while preserving a working tool when download/extraction fails.
REAL_TMUX=$(readlink -f "$(command -v tmux 2>/dev/null || true)" 2>/dev/null || true)
case "$REAL_TMUX" in
    "$BIN_DEST"/*|"$RUNTIME_BASE"/*) REAL_TMUX="" ;;
esac
if [ -n "$REAL_TMUX" ] && "$REAL_TMUX" -V >/dev/null 2>&1; then
    ln -sfn "$REAL_TMUX" "$BIN_DEST/tmux"
else
    if ! "$RUNTIME_BASE/bin/tmux" -V >/dev/null 2>&1; then
        download_verified "$CONFIG_DIR/$TMUX_TAR" "$TMUX_SHA256" "tmux" "$TMUX_URL" || exit 1
        gunzip -c "$CONFIG_DIR/$TMUX_TAR" > "$RUNTIME_WORK/tmux" || exit 1
        publish_binary "$RUNTIME_WORK/tmux" "$RUNTIME_BASE/bin/tmux" -V || exit 1
    fi
    ln -sfn "$RUNTIME_BASE/bin/tmux" "$BIN_DEST/tmux"
fi
# A real system binary must not be removed.
if [ ! -e /usr/local/bin/tmux ] || [ -L /usr/local/bin/tmux ]; then
    ln -sfn "$BIN_DEST/tmux" /usr/local/bin/tmux
fi
"$BIN_DEST/tmux" -V >/dev/null || exit 1

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
# Two rules prevent it coming back:
#   1. Only adopt a "system" tool that resolves to a real executable OUTSIDE our own
#      trees. Anything resolving into $BIN_DEST or $RUNTIME_BASE is our own shim, and
#      anything that does not resolve at all is an existing loop — both mean "install
#      the portable copy", not "adopt it".
#   2. Only replace /usr/local/bin/<n> when it is missing, already broken, or a link
#      into our own trees. A genuine system binary there is never overwritten — which
#      the old `[ ! -e ]` guard got right for a real file but wrong for a loop, because
#      -e is FALSE on a circular symlink, so it happily rebuilt the other half.
install_tool() {
    local tar=$1 url=$2 name=$3 expected_sha=$4
    log_step "$name..."

    local found real=""
    found=$(command -v "$name" 2>/dev/null || true)
    [ -n "$found" ] && real=$(readlink -f "$found" 2>/dev/null || true)
    case "$real" in
        ""|"$BIN_DEST"/*|"$RUNTIME_BASE"/bin/*) real="" ;;   # our own shim, or already looping
    esac

    if [ -n "$real" ] && [ -x "$real" ]; then
        log_ok "System $name found."
        ln -sfn "$real" "$BIN_DEST/$name"
    else
        if ! "$RUNTIME_BASE/bin/$name" --version >/dev/null 2>&1; then
            log_status "    > Downloading portable $name..."
            download_verified "$CONFIG_DIR/$tar" "$expected_sha" "$name" "$url" || return 1
            local tmp
            tmp=$(mktemp -d "$RUNTIME_WORK/extract.XXXXXX") || return 1
            tar -xf "$CONFIG_DIR/$tar" -C "$tmp" --no-same-owner || return 1
            local bin
            bin=$(find "$tmp" -name "$name" -type f | head -n 1)
            [ -n "$bin" ] || { log_fail "$name: binary absent from archive"; return 1; }
            publish_binary "$bin" "$RUNTIME_BASE/bin/$name" --version || return 1
            rm -rf "$tmp"
            log_ok "Portable $name installed."
        else
            log_ok "$name found in plugin root."
        fi
        ln -sfn "$RUNTIME_BASE/bin/$name" "$BIN_DEST/$name"
    fi

    # Publish to /usr/local/bin, and SELF-HEAL a link left looping by an older install.
    local pub="/usr/local/bin/$name" pub_real=""
    [ -L "$pub" ] && pub_real=$(readlink -f "$pub" 2>/dev/null || true)
    if [ ! -e "$pub" ] || { [ -L "$pub" ] && { [ -z "$pub_real" ] || case "$pub_real" in "$BIN_DEST"/*|"$RUNTIME_BASE"/bin/*) true ;; *) false ;; esac; }; }; then
        ln -sfn "$BIN_DEST/$name" "$pub"
    fi
}

# GitHub #13 (part 2): fd and rg are optional conveniences (shell search
# helpers some agents call), not load-bearing for persistence. install_tool
# does `exit 1` on a failed download/checksum, which used to kill this whole
# script — squashfs-tools below never got installed, and every bake/consolidate
# failed with only "Atomic bake failed" and no clue why. Run each of these two
# in a subshell so its internal exit only ends that subshell; a failure here
# is logged and skipped, and squashfs-tools always gets a chance to install.
# git-lfs stays fatal below (it silently drops large files if missing/broken —
# see the 2026-09-09 note above install_tool — so failing loudly is correct).
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

# D-307: Prevent circular symlinks. 
# If 'which' returns our own BIN_DEST or /usr/local/bin symlink, it's not a 'System' tool.
SYS_MKSQUASHFS=$(which mksquashfs 2>/dev/null || true)
IS_SYSTEM=0
if [ -n "$SYS_MKSQUASHFS" ] && [ -f "$SYS_MKSQUASHFS" ]; then
    # If it's not a symlink to our own bin, it's a real system tool
    if [[ "$SYS_MKSQUASHFS" != "$BIN_DEST"* ]] && [[ "$SYS_MKSQUASHFS" != "/usr/local/bin/mksquashfs" ]]; then
        IS_SYSTEM=1
    fi
fi

if [ "$IS_SYSTEM" -eq 1 ]; then
    log_ok "System mksquashfs found at $SYS_MKSQUASHFS."
    rm -f "$BIN_DEST/mksquashfs" "$BIN_DEST/unsquashfs"
    ln -sf "$SYS_MKSQUASHFS" "$BIN_DEST/mksquashfs"
    SYS_UNSQUASHFS=$(which unsquashfs 2>/dev/null || true)
    [ -n "$SYS_UNSQUASHFS" ] && ln -sf "$SYS_UNSQUASHFS" "$BIN_DEST/unsquashfs"
else
    if ! "$RUNTIME_BASE/bin/mksquashfs" -version >/dev/null 2>&1 || ! "$RUNTIME_BASE/bin/unsquashfs" -version >/dev/null 2>&1; then
        download_verified "$CONFIG_DIR/$SQUASH_TAR" "$SQUASH_SHA256" "squashfs-tools" "$SQUASH_URL" "$SQUASH_URL_ALT" || exit 1

        TMP_EXTRACT="$RUNTIME_WORK/squash"
        mkdir -p "$TMP_EXTRACT"
        
        log_status "    > Extracting squashfs-tools..."
        if ! tar -xf "$CONFIG_DIR/$SQUASH_TAR" -C "$TMP_EXTRACT" --no-same-owner 2>&1; then
            log_fail "Extraction of squashfs-tools failed. The download may be corrupted."
            rm -rf "$TMP_EXTRACT"
                exit 1
        fi

        mkdir -p "$RUNTIME_BASE/bin"
        
        # Verify files exist in extraction before copying
        if [ ! -f "$TMP_EXTRACT/usr/bin/mksquashfs" ]; then
            log_fail "mksquashfs binary missing from extracted package."
            rm -rf "$TMP_EXTRACT"
            exit 1
        fi

        publish_binary "$TMP_EXTRACT/usr/bin/mksquashfs" "$RUNTIME_BASE/bin/mksquashfs" -version || exit 1
        publish_binary "$TMP_EXTRACT/usr/bin/unsquashfs" "$RUNTIME_BASE/bin/unsquashfs" -version || exit 1
        
        rm -rf "$TMP_EXTRACT"
        
        if [ -x "$RUNTIME_BASE/bin/mksquashfs" ]; then
            log_ok "Portable squashfs-tools installed successfully."
        else
            log_fail "Failed to finalize squashfs-tools installation."
            exit 1
        fi
    else
        log_ok "squashfs-tools found in plugin root."
    fi
    rm -f "$BIN_DEST/mksquashfs" "$BIN_DEST/unsquashfs"
    ln -sf "$RUNTIME_BASE/bin/mksquashfs" "$BIN_DEST/mksquashfs"
    ln -sf "$RUNTIME_BASE/bin/unsquashfs" "$BIN_DEST/unsquashfs"
fi

# Final Global Symlinks (Ensure they point to our clean BIN_DEST proxy)
rm -f /usr/local/bin/mksquashfs /usr/local/bin/unsquashfs
ln -sf "$BIN_DEST/mksquashfs" /usr/local/bin/mksquashfs
ln -sf "$BIN_DEST/unsquashfs" /usr/local/bin/unsquashfs

# Required tools must actually execute through the plugin paths. Presence alone
# is not sufficient (empty files, broken symlinks, wrong architecture/loader).
for spec in node:--version npm:--version npx:--version tmux:-V git-lfs:--version mksquashfs:-version unsquashfs:-version; do
    tool=${spec%%:*}; flag=${spec#*:}
    if ! timeout 5 "$BIN_DEST/$tool" "$flag" >/dev/null 2>&1; then
        log_fail "Required runtime smoke check failed: $BIN_DEST/$tool $flag"
        exit 1
    fi
done

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
    ln -sf "$wrapper" "/usr/local/bin/$cmd"
}

# Proxy mapping: cmd -> relative path from /agents/ to entry
create_proxy "gemini" "gemini-cli/node_modules/@google/gemini-cli/bundle/gemini.js"
create_proxy "copilot" "gh-copilot/node_modules/@github/copilot/index.js"
# claude-code 2.1.x ships the CLI as a native ELF at bin/claude.exe (older 2.0.x had
# cli.js at the package root — the wrapper's self-heal block falls back to whatever
# package.json "bin" points at if this path is ever absent). Bug #761.
create_proxy "claude" "claude-code/node_modules/@anthropic-ai/claude-code/bin/claude.exe"
create_proxy "opencode" "opencode/node_modules/opencode-ai/bin/opencode"
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
    local log_file="/tmp/unraid-aicliagents/debug.log"

    cat > "$wrapper_tmp" <<'DOCKEREOF'
#!/bin/bash
# AICliAgents Docker Proxy: __CMD__
CMD="__CMD__"
IMAGE="__IMAGE__"
DOCKER_CMD="__DOCKER_CMD__"
DESCRIPTION="__DESCRIPTION__"
LOG="/tmp/unraid-aicliagents/debug.log"

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
    ln -sf "$wrapper" "/usr/local/bin/$cmd"
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
