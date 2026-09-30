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
#   agent_base                  — echo path (root dir holding every agent's mount)
#   agent_mount <agent_id>      — echo path (STABLE — a symlink once versioned)
#   agent_mount_real <agent_id> — echo path (the REAL, symlink-resolved target)
#   agent_versions_dir <agent_id>          — echo path
#   agent_versioned_mount <agent_id> <gen> — echo path
#   agent_generation_entity_key <agent_id> <gen>   — echo the upper/work entity key
#   agent_generation_state_dir <agent_id>          — echo path
#   agent_generation_state_file <agent_id> <gen>   — echo path
#   agent_generation_state_set <agent_id> <gen> <key> <value>
#   agent_generation_state_get <agent_id> <gen> <key>  — echo value
#   agent_generation_state_clear <agent_id> <gen>
#   agent_live_generation <agent_id>               — echo the activated generation id
#   agent_mounted_generations <agent_id>           — echo bound generation ids, newest first
#   agent_generation_count <agent_id>              — echo how many are bound
#   agent_staging_mount <agent_id>                 — echo path (install-time mount)
#   agent_staging_entity_key <agent_id>            — echo the staging upper/work key
#   zram_upper <type> <id>      — echo path
#   zram_work  <type> <id>      — echo path
#   home_live_upper <user>      — echo the upper the home mount REALLY uses (#372)
#   home_uppers_live            — echo "<user>\t<upper>" per home to save (#372)
#   home_uppers_shutdown_order  — the same list, zram uppers first (#372)
#
# Lifecycle log writer (pure-bash, same format as PHP LifecycleLogService):
#   lifecycle_log <level> <component> <event> [json_payload]

# ---- Constants ---------------------------------------------------------------

PLUGIN_BASE="/boot/config/plugins/unraid-aicliagents"
MOUNT_ROOT="/tmp/unraid-aicliagents"
ZRAM_BASE="$MOUNT_ROOT/zram_upper"
CONFIG_FILE="$PLUGIN_BASE/unraid-aicliagents.cfg"
# AICLI_EMHTTP_AGENTS is a TEST-ONLY override (mirrors the AICLI_MANIFEST_PATH /
# AICLI_LIFECYCLE_LOG / AICLI_PROC_MOUNTS precedent elsewhere in this file and
# common.sh) so the Phase 2 symlink-activation functions below can be unit
# tested against a throwaway tmpdir instead of the real, root-owned emhttp
# path. Unset in production -> the literal below, unchanged from Phase 1.
EMHTTP_AGENTS="${AICLI_EMHTTP_AGENTS:-/usr/local/emhttp/plugins/unraid-aicliagents/agents}"

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

# agent_base
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1 (2026-09-09): the shell twin of PHP's
# AgentRegistry::agentBase() — the root directory holding every agent's own
# overlay-mounted directory, for callers that need to glob/iterate ALL agents
# rather than resolve one (agent_mount below is for that case).
agent_base() {
    _rp_normalize "$EMHTTP_AGENTS"
}

# agent_mount <agent_id>
# The STABLE name every ordinary caller should keep using — unchanged by
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 2 (2026-09-09). Once an agent has been
# migrated to the versioned layout this is a symlink into
# agent_versions_dir/<generation>; file I/O through it (open/read/exec/npm
# require, etc.) is transparent either way. Callers that need the REAL,
# symlink-resolved location — because they are about to match a literal path
# against /proc/mounts, which records the kernel's real mount target and never
# the symlink name — must use agent_mount_real instead.
agent_mount() {
    local id="${1:-}"
    _rp_normalize "$EMHTTP_AGENTS/$id"
}

# agent_mount_real <agent_id>
# Phase 2: the REAL directory agent_mount's stable name currently resolves to.
# `readlink -f` follows the symlink (once migrated) all the way down; a
# not-yet-migrated legacy real directory, or an agent that was never installed,
# resolves to itself (readlink -f on a plain dir/absent path returns it
# unchanged when it exists, or fails — in which case we fall back to the
# stable path verbatim, exactly Phase 1's behaviour). This is the one function
# every /proc/mounts-literal-match call site (the busy-arbiter, lowerdir
# readers) must use instead of agent_mount, mirroring
# AgentRegistry::agentLiveMountTarget() on the PHP side.
agent_mount_real() {
    local id="${1:-}" stable resolved
    stable="$(agent_mount "$id")"
    resolved="$(readlink -f "$stable" 2>/dev/null)"
    if [ -n "$resolved" ]; then
        printf '%s\n' "$resolved"
    else
        printf '%s\n' "$stable"
    fi
}

# agent_versions_dir <agent_id>
# Phase 2: the directory holding every installed generation of one agent's
# overlay mountpoint — agents/.versions/<id>/. Mirrors the plugin's own
# .generations/<id> (generation.sh), one level deeper because this root also
# holds the OTHER agents' version directories side by side (agentBase() is
# shared across every agent id, .generations/<id> is already per-agent).
agent_versions_dir() {
    local id="${1:-}"
    _rp_normalize "$EMHTTP_AGENTS/.versions/$id"
}

# agent_versioned_mount <agent_id> <generation_id>
# Phase 2: the REAL, version-qualified overlay mountpoint for one generation of
# one agent — agents/.versions/<id>/<gen>. This is what op_mount actually binds
# the overlay to once the agent has at least one baked layer to derive <gen>
# from; agent_mount's stable name is then symlinked to it via
# agent_activate_stable_symlink below.
agent_versioned_mount() {
    local id="${1:-}" gen="${2:-}"
    _rp_normalize "$(agent_versions_dir "$id")/$gen"
}

# agent_activate_stable_symlink <agent_id> <generation_id> <versioned_mount>
# Phase 2: atomically point the stable agent_mount name at the freshly-bound
# versioned mount, mirroring aicli_activate_generation's `mv -Tf` swap
# (src/scripts/installer/generation.sh) — stage the new symlink under a
# temp name, then rename it over the stable name in one syscall so there is
# never a window where the stable name is missing or half-written. Must be
# called ONLY after the overlay at <versioned_mount> is confirmed mounted —
# op_mount's caller is responsible for that ordering (see its own comment):
# flipping this before the mount succeeds would let a new session resolve a
# name with nothing behind it yet.
#
# Handles the one-time migration case (the stable name is still a REAL
# directory — either a genuinely legacy pre-Phase-2 install, or this agent's
# very first versioned activation, where op_mount's step 2 `mkdir -p` created
# it empty): op_mount's busy-arbiter has, by the time this runs, already torn
# down whatever overlay used to be bound there (deferred instead of calling
# this at all if that teardown was not safe), so the directory is empty and
# safe to remove before the symlink takes its name — exactly the legacy-adopt
# step aicli_activate_generation performs for the plugin's own src/ directory.
#
# Also clears away any EMPTY leftover generation directory under this agent's
# agent_versions_dir. Since Phase 3 (2026-09-15) a sibling directory is NOT
# automatically stale — it may be a generation an older session is still
# running from — so the two guards below carry real weight rather than being
# belt-and-braces: a directory with an overlay bound to it is skipped outright,
# and `rmdir` (never `rm -rf`) means a directory with anything in it is refused
# rather than destroyed. Deciding which BOUND generations may be released is
# not this function's job; that is aicli_gc_agent_generations' reference-counted
# sweep (src/scripts/installer/generation.sh), which op_mount calls immediately
# after a successful activation.
agent_activate_stable_symlink() {
    local id="${1:-}" gen="${2:-}" versioned_mount="${3:-}"
    [ -n "$id" ] && [ -n "$gen" ] && [ -n "$versioned_mount" ] || return 1
    local stable rel_target link_tmp versions_dir gen_dir
    stable="$(agent_mount "$id")"
    rel_target=".versions/$id/$gen"

    if [ -e "$stable" ] && [ ! -L "$stable" ]; then
        # `rmdir` only — never `rm -rf`. By construction this should always be
        # an empty, just-unmounted overlay mountpoint (or one op_mount's own
        # step 2 `mkdir -p` created empty for a first-ever activation); if it
        # is somehow non-empty, that is unexpected and the safe failure mode
        # is to refuse the activation, not silently destroy whatever is really
        # in there. op_mount's caller already treats a failed activation as
        # non-fatal (the overlay stays correctly mounted, just not yet
        # reachable via the stable name) and retries on the next mount cycle.
        rmdir "$stable" 2>/dev/null || return 1
    fi

    link_tmp="${stable}.next.$$"
    rm -f "$link_tmp" 2>/dev/null
    ln -s "$rel_target" "$link_tmp" || return 1
    if ! mv -Tf "$link_tmp" "$stable"; then
        rm -f "$link_tmp"
        return 1
    fi

    versions_dir="$(agent_versions_dir "$id")"
    if [ -d "$versions_dir" ]; then
        for gen_dir in "$versions_dir"/*/; do
            [ -d "$gen_dir" ] || continue
            gen_dir="${gen_dir%/}"
            [ "$gen_dir" = "$versioned_mount" ] && continue
            mountpoint -q "$gen_dir" 2>/dev/null && continue
            rmdir "$gen_dir" 2>/dev/null || true
        done
    fi
    return 0
}

# ---- Phase 3: side-by-side generations ---------------------------------------
# docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15). Phase 2 gave
# one agent a version-qualified mountpoint behind a stable symlink but kept a
# SINGLE writable upper per agent, so only one generation could ever be live.
# These helpers add the per-generation writable layer and the small state record
# that makes two live generations of one agent safe.
#
# The entity key trick: _entity_paths (common.sh) already derives upper/work
# from an entity id. A generation-scoped upper is therefore just that same
# derivation keyed by "<id>@<gen>" instead of "<id>" — no second, parallel path
# grammar to keep in sync, and zram mode gets versioned uppers for free.

# agent_generation_entity_key <agent_id> <generation_id>
# The entity id _entity_paths should key this generation's upper/work on.
agent_generation_entity_key() {
    local id="${1:-}" gen="${2:-}"
    if [ -z "$gen" ]; then
        printf '%s\n' "$id"
    else
        printf '%s@%s\n' "$id" "$gen"
    fi
}

# agent_generation_state_dir <agent_id>
# Holds one small key=value record per generation. It CANNOT live inside the
# generation directory itself — that directory is the overlay mountpoint, so
# anything written there before the mount is shadowed by it, and anything
# written after lands in the generation's own upper. A sibling dot-directory
# under agent_versions_dir is outside every mountpoint and survives both.
agent_generation_state_dir() {
    local id="${1:-}"
    _rp_normalize "$(agent_versions_dir "$id")/.state"
}

# agent_generation_state_file <agent_id> <generation_id>
agent_generation_state_file() {
    local id="${1:-}" gen="${2:-}"
    _rp_normalize "$(agent_generation_state_dir "$id")/$gen.env"
}

# agent_generation_state_set <agent_id> <generation_id> <key> <value>
# Upserts one key. Atomic temp+rename so a concurrent reader never sees a
# half-written record (the same discipline agent_activate_stable_symlink uses
# for the symlink itself). Keys are [a-z_]; a value may not contain a newline.
agent_generation_state_set() {
    local id="${1:-}" gen="${2:-}" key="${3:-}" val="${4:-}" f tmp
    [ -n "$id" ] && [ -n "$gen" ] && [ -n "$key" ] || return 1
    case "$key" in *[!a-z_]*) return 1 ;; esac
    case "$val" in *$'\n'*) return 1 ;; esac
    f="$(agent_generation_state_file "$id" "$gen")"
    mkdir -p "$(dirname "$f")" 2>/dev/null || return 1
    tmp="$f.tmp.$$"
    { [ -f "$f" ] && grep -v "^${key}=" "$f" 2>/dev/null; printf '%s=%s\n' "$key" "$val"; } > "$tmp" || { rm -f "$tmp"; return 1; }
    mv -f "$tmp" "$f" || { rm -f "$tmp"; return 1; }
    return 0
}

# agent_generation_state_get <agent_id> <generation_id> <key>
# Echoes the value, or nothing when the record or key is absent.
agent_generation_state_get() {
    local id="${1:-}" gen="${2:-}" key="${3:-}" f
    [ -n "$id" ] && [ -n "$gen" ] && [ -n "$key" ] || return 1
    f="$(agent_generation_state_file "$id" "$gen")"
    [ -f "$f" ] || return 1
    sed -n "s/^${key}=//p" "$f" 2>/dev/null | tail -1
}

# agent_generation_state_clear <agent_id> <generation_id>
agent_generation_state_clear() {
    local id="${1:-}" gen="${2:-}"
    [ -n "$id" ] && [ -n "$gen" ] || return 1
    rm -f "$(agent_generation_state_file "$id" "$gen")" 2>/dev/null
    return 0
}

# agent_live_generation <agent_id>
# The generation id the stable agent_mount symlink currently points at, or
# nothing when the agent is not on the versioned layout yet. Reads the link
# text rather than the resolved path so it works even if the target is
# missing (a half-failed activation) — the caller wants to know what was
# ACTIVATED, not what happens to exist.
agent_live_generation() {
    local id="${1:-}" stable target
    stable="$(agent_mount "$id")"
    [ -L "$stable" ] || return 1
    target="$(readlink "$stable" 2>/dev/null)" || return 1
    case "$target" in
        ".versions/$id/"*) printf '%s\n' "${target#.versions/$id/}" ;;
        *) return 1 ;;
    esac
}

# agent_mounted_generations <agent_id>
# Every generation id of this agent with a live overlay bound to it, newest
# first by directory mtime. A directory with no overlay bound is NOT listed —
# it is an empty leftover, not a generation anything can be running from.
agent_mounted_generations() {
    local id="${1:-}" versions_dir gen_dir
    versions_dir="$(agent_versions_dir "$id")"
    [ -d "$versions_dir" ] || return 0
    for gen_dir in $(ls -1dt "$versions_dir"/*/ 2>/dev/null); do
        gen_dir="${gen_dir%/}"
        case "$(basename -- "$gen_dir")" in .*) continue ;; esac
        mountpoint -q "$gen_dir" 2>/dev/null || continue
        basename -- "$gen_dir"
    done
}

# agent_generation_count <agent_id> — how many generations are bound right now.
agent_generation_count() {
    agent_mounted_generations "${1:-}" | grep -c . || true
}

# agent_generation_bound_upper <agent_id> <generation_id> [option]
# The writable layer the kernel is ACTUALLY using for this generation, read out
# of /proc/mounts rather than derived. `option` selects which overlay option to
# read — "upperdir" (default) or "workdir"; both must come from the same place,
# because the two are not derivable from one another in every upper mode (a zram
# upper is <base>/<id>/upper and <base>/<id>/work, a disk upper is _upper/<id>
# and _work/<id> — no single string substitution covers both).
#
# Needed for generations bound by the Phase 2 code, which wrote no state record
# because there was only one writable layer per agent. Deriving their layer would
# guess wrong: they use the id-keyed path, not the versioned one. Reading the
# mount table is the only answer that cannot be wrong, and it is what lets a
# superseded generation's layer be reclaimed instead of stranded on flash.
agent_generation_bound_upper() {
    local id="${1:-}" gen="${2:-}" opt="${3:-upperdir}" target line
    [ -n "$id" ] && [ -n "$gen" ] || return 1
    target="$(agent_versioned_mount "$id" "$gen")"
    while IFS= read -r line; do
        case "$line" in
            "overlay $target overlay "*)
                # Take FIELD 4 (the option list) before splitting on commas. A
                # /proc/mounts line ends with two more space-separated fields,
                # so splitting the whole line would return "<path> 0 0" whenever
                # the option asked for happens to be the last one — which it is
                # for workdir on a mount without redirect_dir. Caught by the
                # test, not by reading the kernel's output for one example mount.
                # A path with a space is octal-escaped by the kernel and is not a
                # shape this plugin creates.
                printf '%s\n' "$line" | awk '{print $4}' | tr ',' '\n' | sed -n "s/^${opt}=//p" | head -1
                return 0
                ;;
        esac
    done < "${AICLI_PROC_MOUNTS:-/proc/mounts}"
    return 1
}

# agent_generation_upper_is_shared <agent_id> <generation_id> <upper_path>
# True when a generation OTHER than <generation_id> records the same writable
# layer. The reaper asks this before removing a layer that is not version-keyed:
# the one thing it must never do is delete a layer another generation is still
# mounted on.
agent_generation_upper_is_shared() {
    local id="${1:-}" gen="${2:-}" upper="${3:-}" f other
    [ -n "$id" ] && [ -n "$upper" ] || return 1
    for f in "$(agent_generation_state_dir "$id")"/*.env; do
        [ -f "$f" ] || continue
        other="$(basename -- "$f" .env)"
        [ "$other" = "$gen" ] && continue
        [ "$(agent_generation_state_get "$id" "$other" upper 2>/dev/null || true)" = "$upper" ] && return 0
    done
    return 1
}

# agent_staging_mount <agent_id>
# SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 3 (2026-09-15): where a NEW version of an
# agent is installed, while the version in service keeps running untouched.
#
# The install cannot write into the live generation's layer. Today `npm install`
# runs straight against the merged view of the live mount, so the new version is
# in the running sessions' own writable layer before the mount swap is even
# considered — which is why an upgrade had to close every session first. Giving
# the install a mount of its own, over the SAME read-only lower stack but with a
# fresh writable layer of its own, is what makes "install now, switch when you
# reload" true rather than nearly true.
#
# It sits under the agents root (a dot-directory, so it can never be mistaken
# for an agent id) rather than in /tmp: it is the same filesystem shape the
# final mount has, and an npm tree that resolves through the same number of
# directory levels is one fewer difference between staging and serving.
agent_staging_mount() {
    local id="${1:-}"
    _rp_normalize "$EMHTTP_AGENTS/.staging/$id"
}

# agent_staging_entity_key <agent_id>
# The entity key the staging writable layer is derived from. Deliberately shares
# the "<id>@<suffix>" grammar of a generation key so a stray staging layer is
# recognisable next to the real ones — but "@staging" can never collide with a
# real generation id, which always ends in a 16-hex content digest.
agent_staging_entity_key() {
    printf '%s@staging\n' "${1:-}"
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

# ---- #372: the upper a home REALLY uses --------------------------------------
# docs/specs/HOME_STORAGE_LIFECYCLE.md "2026-09-30 — the live mount wins".
# The supervisor and the shutdown bake do not source common.sh, so they read the
# mount table here. A mounted home's upper is the overlay's own upperdir, in
# whatever mode it was mounted (zram or disk); the policy mode applies only to a
# new mount. AICLI_PROC_MOUNTS stubs the table for unit tests.

# _rp_overlay_upper_at <mnt> -> the upperdir of the overlay mounted at exactly
# <mnt> (the last row wins). Returns 1 when none is mounted there.
_rp_overlay_upper_at() {
    local mnt="${1:-}"
    [ -n "$mnt" ] || return 1
    awk -v m="$mnt" '
        $2==m && $3=="overlay" {
            v = ""; n = split($4, a, ",")
            for (i = 1; i <= n; i++) if (index(a[i], "upperdir=") == 1) v = substr(a[i], 10)
            last = v; found = 1
        }
        END { if (found && last != "") { print last; exit 0 } exit 1 }' "${AICLI_PROC_MOUNTS:-/proc/mounts}" 2>/dev/null
}

# home_live_upper <user> -> the upper of the mounted home overlay; when the home
# is not mounted, the zram upper (the only upper that exists without a mount and
# is lost at a reboot).
home_live_upper() {
    local up
    up="$(_rp_overlay_upper_at "$(home_mount "${1:-root}")")" && [ -n "$up" ] && { printf '%s\n' "$up"; return 0; }
    zram_upper home "$(_rp_normalize_user "${1:-root}")"
}

# home_uppers_live -> one line "<user><TAB><upper>" for each home that has an
# upper to save: every home overlay mounted at $MOUNT_ROOT/work/<user>/home
# (its live upper, zram or disk), then every zram home upper whose home is not
# mounted. Never lists one home twice.
home_uppers_live() {
    local seen=" " user up d
    while IFS=$'\t' read -r user up; do
        [ -n "$user" ] && [ -n "$up" ] || continue
        case "$seen" in *" $user "*) continue ;; esac
        seen="$seen$user "
        printf '%s\t%s\n' "$user" "$up"
    done < <(awk -v r="$MOUNT_ROOT/work/" '
        $3=="overlay" && index($2, r) == 1 {
            rest = substr($2, length(r) + 1)
            if (rest !~ /^[^\/]+\/home$/) next
            u = rest; sub(/\/home$/, "", u)
            v = ""; n = split($4, a, ",")
            for (i = 1; i <= n; i++) if (index(a[i], "upperdir=") == 1) v = substr(a[i], 10)
            if (v != "") up[u] = v
        }
        END { for (u in up) printf "%s\t%s\n", u, up[u] }' "${AICLI_PROC_MOUNTS:-/proc/mounts}" 2>/dev/null | sort)
    for d in "$ZRAM_BASE/homes"/*/upper; do
        [ -d "$d" ] || continue
        user="${d%/upper}"; user="${user##*/}"
        case "$seen" in *" $user "*) continue ;; esac
        seen="$seen$user "
        printf '%s\t%s\n' "$user" "$d"
    done
}

# home_uppers_shutdown_order -> home_uppers_live, the uppers in RAM (zram) first:
# they are lost at power-off, so they get the shutdown time budget first. A disk
# upper survives the power-off, and the next mount adopts it, but it is baked too.
home_uppers_shutdown_order() {
    local all
    all="$(home_uppers_live)"
    [ -n "$all" ] || return 0
    printf '%s\n' "$all" | awk -F'\t' -v z="$ZRAM_BASE/" 'NF>=2 && index($2, z) == 1'
    printf '%s\n' "$all" | awk -F'\t' -v z="$ZRAM_BASE/" 'NF>=2 && index($2, z) != 1'
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
    # "${4:-{}}" is NOT a default of {}: bash ends the expansion at the first
    # "}", so every given payload got a stray "}" (HOME_BACKUP.md 2026-09-29).
    local payload_json="${4-}"
    [ -n "$payload_json" ] || payload_json='{}'

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
