#!/bin/bash
# aicli-guard-render.sh <out-file>
#
# Layer 2 of the share-filesystem guard (docs/specs/SHFS_RECURSIVE_READ_GUARD.md, #972).
# Writes the context file that the shell wrapper `aicli-guard-shim` sources. The file holds:
#   - the guarded roots with a class (shfs = Unraid share filesystem, net = network mount),
#   - the mounts that sit inside a guarded root with a safe type (a pool folder bound over a share path),
#   - the pool path of each share (from the share config files),
#   - the ancestors, the allowed find depth, the command list and the refusal texts.
# The rule list is src/includes/guard/shfs-guard-rules.json. The same list feeds the PHP checker.
#
# Never touches a guarded path: it reads /proc/self/mountinfo and the share config files only.
# Test overrides: AICLI_GUARD_RULES, AICLI_GUARD_MOUNTINFO, AICLI_GUARD_SHARES_DIR.
# Exit 0 = the file is written (atomically). Any other exit = no file; the caller runs without the guard.

OUT="${1:-}"
[ -n "$OUT" ] || { echo "usage: aicli-guard-render.sh <out-file>" >&2; exit 2; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
RULES="${AICLI_GUARD_RULES:-$HERE/../../includes/guard/shfs-guard-rules.json}"
MOUNTINFO="${AICLI_GUARD_MOUNTINFO:-/proc/self/mountinfo}"
SHARES_DIR="${AICLI_GUARD_SHARES_DIR:-/boot/config/shares}"

[ -r "$RULES" ] || { echo "aicli-guard-render: rule list not readable: $RULES" >&2; exit 3; }
[ -r "$MOUNTINFO" ] || { echo "aicli-guard-render: mountinfo not readable: $MOUNTINFO" >&2; exit 3; }

# 1. The rule list as tab separated lines: kind<TAB>a[<TAB>b]. jq on the hosts; php (also on the hosts and in the CI
#    container, which has no jq) as the fallback.
_rules_tsv() {
    if command -v jq >/dev/null 2>&1; then
        jq -r '(.fixedRoots[]|"fixed\t"+.), (.fstypes[]|"fstype\t"+.), (.ancestors[]|"ancestor\t"+.),
               (.commands[]|"command\t"+.), "maxdepth\t\(.maxDepthAllowed)",
               (.texts|to_entries[]|"text\t\(.key)\t\(.value)")' "$RULES"
    elif command -v php >/dev/null 2>&1; then
        php -r '$j = json_decode(file_get_contents($argv[1]), true);
            if (!is_array($j)) { exit(1); }
            foreach ($j["fixedRoots"] as $v) echo "fixed\t$v\n";
            foreach ($j["fstypes"] as $v) echo "fstype\t$v\n";
            foreach ($j["ancestors"] as $v) echo "ancestor\t$v\n";
            foreach ($j["commands"] as $v) echo "command\t$v\n";
            echo "maxdepth\t" . (int)$j["maxDepthAllowed"] . "\n";
            foreach ($j["texts"] as $k => $v) echo "text\t$k\t$v\n";' "$RULES"
    else
        return 4
    fi
}

TSV="$(_rules_tsv)" || { echo "aicli-guard-render: cannot read the rule list (jq or php needed)" >&2; exit 4; }
[ -n "$TSV" ] || { echo "aicli-guard-render: rule list is empty" >&2; exit 4; }

declare -a FIXED=() FSTYPES=() ANCESTORS=() COMMANDS=()
declare -A TEXTS=()
MAXDEPTH=1
while IFS=$'\t' read -r k a b; do
    case "$k" in
        fixed) FIXED+=("$a") ;;
        fstype) FSTYPES+=("$a") ;;
        ancestor) ANCESTORS+=("$a") ;;
        command) COMMANDS+=("$a") ;;
        maxdepth) MAXDEPTH="$a" ;;
        text) TEXTS["$a"]="$b" ;;
    esac
done <<< "$TSV"
[[ "$MAXDEPTH" =~ ^[0-9]+$ ]] || MAXDEPTH=1
{ [ "${#COMMANDS[@]}" -gt 0 ] && [ "${#FIXED[@]}" -gt 0 ]; } || { echo "aicli-guard-render: rule list has no commands or roots" >&2; exit 4; }

_is_guarded_type() {
    local t
    for t in "${FSTYPES[@]}"; do [ "$t" = "$1" ] && return 0; done
    return 1
}

# 2. Guarded roots: the fixed roots first, then every mount whose type is in the guarded set.
declare -a ROOTS=() CLASSES=() ALLOW=()
_add_root() { # path class
    local i
    for i in "${!ROOTS[@]}"; do [ "${ROOTS[$i]}" = "$1" ] && return 0; done
    ROOTS+=("$1"); CLASSES+=("$2")
}
for r in "${FIXED[@]}"; do
    case "$r" in
        /mnt/remotes|/mnt/remotes/*) _add_root "$r" net ;;
        *) _add_root "$r" shfs ;;
    esac
done

# mountpoint<TAB>fstype per mount. A mount point with an escape (a space or a backslash) is skipped: it is rare and
# the escaped form cannot match a path.
mapfile -t MOUNTS < <(awk '{ for (i = 6; i <= NF; i++) if ($i == "-") break;
        if (i < NF && $5 !~ /\\/) printf "%s\t%s\n", $5, $(i + 1) }' "$MOUNTINFO")
for line in "${MOUNTS[@]}"; do
    mp="${line%%$'\t'*}"; ft="${line#*$'\t'}"
    [ "$mp" = "/" ] && continue
    if _is_guarded_type "$ft"; then
        if [ "$ft" = "fuse.shfs" ]; then _add_root "$mp" shfs; else _add_root "$mp" net; fi
    fi
done
# A mount of a safe type STRICTLY inside a guarded root (a pool folder bound over a share path, as the helper view
# does) is not guarded: layer 2 allows recursive work there. A mount at the same path as a root does not count.
for line in "${MOUNTS[@]}"; do
    mp="${line%%$'\t'*}"; ft="${line#*$'\t'}"
    _is_guarded_type "$ft" && continue
    for r in "${ROOTS[@]}"; do
        if [[ "$mp" == "$r"/* ]]; then ALLOW+=("$mp"); break; fi
    done
done

# 3. The pool path of each share: pool only, one pool, gives /mnt/<pool>/<share>. Anything else gives none.
declare -A POOLS=()
shopt -s nullglob
for f in "$SHARES_DIR"/*.cfg; do
    share="${f##*/}"; share="${share%.cfg}"
    use=""; pool=""; pool2=""
    while IFS= read -r l || [ -n "$l" ]; do
        l="${l%$'\r'}"
        case "$l" in
            shareUseCache=*) use="${l#shareUseCache=}" ;;
            shareCachePool=*) pool="${l#shareCachePool=}" ;;
            shareCachePool2=*) pool2="${l#shareCachePool2=}" ;;
        esac
    done < "$f"
    use="${use//[\"\' ]/}"; pool="${pool//[\"\' ]/}"; pool2="${pool2//[\"\' ]/}"
    if [ "$use" = "only" ] && [ -n "$pool" ] && [ -z "$pool2" ] && [[ "$pool" != */* ]]; then
        POOLS["$share"]="/mnt/$pool/$share"
    else
        POOLS["$share"]=""
    fi
done
shopt -u nullglob

# 4. Write the context file next to its final name, then move it into place.
TMP="$(mktemp "$OUT.XXXXXX" 2>/dev/null)" || { echo "aicli-guard-render: cannot write next to $OUT" >&2; exit 5; }
{
    printf '# aicli share-filesystem guard context (generated; do not edit). Spec: docs/specs/SHFS_RECURSIVE_READ_GUARD.md\n'
    printf 'AICLI_GUARD_ROOTS=('; for r in "${ROOTS[@]}"; do printf ' %q' "$r"; done; printf ' )\n'
    printf 'AICLI_GUARD_CLASSES=('; for r in "${CLASSES[@]}"; do printf ' %q' "$r"; done; printf ' )\n'
    printf 'AICLI_GUARD_ALLOW=('; for r in "${ALLOW[@]}"; do printf ' %q' "$r"; done; printf ' )\n'
    printf 'AICLI_GUARD_ANCESTORS=('; for r in "${ANCESTORS[@]}"; do printf ' %q' "$r"; done; printf ' )\n'
    printf 'AICLI_GUARD_COMMANDS=('; for r in "${COMMANDS[@]}"; do printf ' %q' "$r"; done; printf ' )\n'
    printf 'AICLI_GUARD_MAXDEPTH=%q\n' "$MAXDEPTH"
    printf 'declare -A AICLI_GUARD_POOLS=('
    for s in "${!POOLS[@]}"; do printf ' [%q]=%q' "$s" "${POOLS[$s]}"; done
    printf ' )\n'
    for k in "${!TEXTS[@]}"; do printf 'AICLI_GUARD_TEXT_%s=%q\n' "$k" "${TEXTS[$k]}"; done
    # R14: the script that raises the event `guard.refused` (src/scripts/aicli-guard-event.php, next to scripts/guard).
    printf 'AICLI_GUARD_EVENT_SCRIPT=%q\n' "$HERE/../aicli-guard-event.php"
    cat <<'HOOK'
# Hook for the refusal event (R14). $1 = command, $2 = kind, $3 = command line. It appends one line to $AICLI_GUARD_LOG
# when that variable names a file (a test seam), and starts the event script in the background, detached, so the
# refusal is not slowed. The script writes the event `guard.refused` and the Activity tray row, at most one for each
# workspace in 60 seconds. Not started when AICLI_GUARD_TEST=1. Never fails.
aicli_guard_on_refusal() {
    local c="${3:0:200}"
    c="${c//$'\n'/ }"
    if [ -n "${AICLI_GUARD_LOG:-}" ]; then
        printf '%(%s)T guard.refused session=%s cmd=%s kind=%s line=%s\n' -1 "${AICLI_SESSION_ID:-}" "$1" "$2" "$c" >> "$AICLI_GUARD_LOG" 2>/dev/null
    fi
    if [ "${AICLI_GUARD_TEST:-}" != "1" ] && [ -n "${AICLI_GUARD_EVENT_SCRIPT:-}" ] && [ -r "$AICLI_GUARD_EVENT_SCRIPT" ]; then
        ( setsid php "$AICLI_GUARD_EVENT_SCRIPT" --layer=shell --agent="${AGENT_ID:-}" --kind="$2" --cmd="$c" </dev/null >/dev/null 2>&1 & ) 2>/dev/null
    fi
    return 0
}
HOOK
} > "$TMP" || { rm -f "$TMP"; echo "aicli-guard-render: write failed" >&2; exit 5; }
chmod 644 "$TMP" && mv -f "$TMP" "$OUT" || { rm -f "$TMP"; echo "aicli-guard-render: cannot place $OUT" >&2; exit 5; }
exit 0
