#!/bin/bash
# syslog-keep.sh — keep a copy of the system log on the flash drive or a pool,
# so it survives a reboot or a hard stop (Forgejo #392,
# docs/specs/HARD_STOP_RESILIENCE.md R5).
#
# Sourced by aicli-supervisor.sh. The supervisor calls _syslog_keep_tick on
# every tick. The function acts only every syslog_keep_interval_s seconds:
#   1. At the first call after a boot, a latest file that is older than the
#      boot time is the log of the PREVIOUS boot. It is renamed to
#      aicli-syslog-previous-<UTC time>.txt. The newest 3 of those are kept.
#   2. It copies the last 2 MB of the system log to aicli-syslog-latest.txt
#      (temp file, then mv, so the file is never half written). It copies only
#      when the size or the mtime of the system log has changed.
# Every step is best effort. A failure is logged once and never stops the
# supervisor. The function always returns 0.
#
# Settings (GitHub #37), read from the plugin cfg once per round, so a change
# applies without a restart:
#   syslog_keep_location   flash (default): /boot/logs, as before these keys.
#                          pool: the folder in syslog_keep_path.
#                          off: no copy.
#   syslog_keep_path       a folder under /mnt/<pool>. It must exist on a
#                          mounted disk. It is never created here: while its
#                          pool is not mounted, mkdir would write into /mnt in RAM.
#   syslog_keep_interval_s seconds between two rounds, 60 to 86400 (default 300).
#
# Test overrides (environment): AICLI_SYSLOG_SRC, AICLI_SYSLOG_DST_DIR,
# AICLI_SYSLOG_NOW (epoch seconds), AICLI_BOOT_TIME (epoch seconds),
# AICLI_SYSLOG_INTERVAL (seconds), AICLI_SYSLOG_KEEP_BYTES. An override wins
# over the setting.

_SYSLOG_KEEP_LAST=0        # epoch of the last attempt
_SYSLOG_KEEP_SIG=""        # destination|size:mtime of the system log at the last copy
_SYSLOG_KEEP_BOOT_DONE=0   # 1 once the boot check ran in this process
_SYSLOG_KEEP_FAIL_LOGGED=0 # 1 once a write failure was logged
_SYSLOG_KEEP_INTERVAL=300  # syslog_keep_interval_s as read at the last round
_SYSLOG_KEEP_SKIP_LOGGED=0 # 1 once "the pool folder is not available" was logged

# _syslog_keep_cfg <key> <default> — a setting from the plugin cfg, or <default>.
_syslog_keep_cfg() {
    local v=""
    if declare -f _rp_read_cfg >/dev/null 2>&1; then
        v="$(_rp_read_cfg "$1" 2>/dev/null)"
    fi
    printf '%s' "${v:-$2}"
}

# _syslog_keep_pool_dir_ready <dir> — true when <dir> is a folder under /mnt
# that exists on a mounted, durable disk (not /mnt itself, which is rootfs on
# Unraid, and not a tmpfs).
_syslog_keep_pool_dir_ready() {
    local dir="${1:-}" fst
    case "$dir" in /mnt/?*) ;; *) return 1 ;; esac
    [ -d "$dir" ] || return 1
    command -v findmnt >/dev/null 2>&1 || return 0
    fst="$(findmnt --noheadings --output FSTYPE --target "$dir" 2>/dev/null)"
    case "$fst" in
        ''|rootfs|tmpfs|ramfs|devtmpfs|overlay|zram|squashfs) return 1 ;;
    esac
    return 0
}

# _syslog_keep_note <level> <message> — log with the supervisor logger when it
# exists (a test that sources only this file has none).
_syslog_keep_note() {
    if declare -f _log >/dev/null 2>&1; then
        _log "$1" "$2" 2>/dev/null || true
    else
        printf '[syslog-keep] %s %s\n' "$1" "$2" >&2
    fi
}

# _syslog_keep_boot_time — the boot time as epoch seconds ("" when unknown).
_syslog_keep_boot_time() {
    if [ -n "${AICLI_BOOT_TIME:-}" ]; then
        printf '%s\n' "$AICLI_BOOT_TIME"
        return 0
    fi
    awk '/^btime /{print $2; exit}' /proc/stat 2>/dev/null
}

# _syslog_keep_boot_archive <dir> — keep the previous boot's latest file.
_syslog_keep_boot_archive() {
    local dir="$1" latest="$1/aicli-syslog-latest.txt" boot mt stamp f n=0
    boot="$(_syslog_keep_boot_time)"
    case "$boot" in ''|*[!0-9]*) return 0 ;; esac
    if [ -f "$latest" ]; then
        mt="$(stat -c '%Y' "$latest" 2>/dev/null)"
        case "$mt" in ''|*[!0-9]*) mt="" ;; esac
        if [ -n "$mt" ] && [ "$mt" -lt "$boot" ]; then
            stamp="$(date -u -d "@$mt" +%Y%m%dT%H%M%SZ 2>/dev/null)"
            [ -n "$stamp" ] || stamp="$(date -u +%Y%m%dT%H%M%SZ)"
            if mv -f "$latest" "$dir/aicli-syslog-previous-$stamp.txt" 2>/dev/null; then
                _syslog_keep_note "INFO" "syslog-keep: kept the log of the previous boot as aicli-syslog-previous-$stamp.txt"
            fi
        fi
    fi
    # Keep the newest 3 previous files. The UTC time in the name sorts as text.
    for f in $(ls -1 "$dir"/aicli-syslog-previous-*.txt 2>/dev/null | sort -r); do
        n=$((n + 1))
        [ "$n" -le 3 ] || rm -f "$f" 2>/dev/null || true
    done
    return 0
}

_syslog_keep_tick() {
    local src="${AICLI_SYSLOG_SRC:-/var/log/syslog}"
    local interval="${AICLI_SYSLOG_INTERVAL:-$_SYSLOG_KEEP_INTERVAL}"
    local keep_bytes="${AICLI_SYSLOG_KEEP_BYTES:-2097152}"
    local now sig tmp dir dest loc="flash"
    now="${AICLI_SYSLOG_NOW:-$(date +%s)}"
    case "$interval" in ''|*[!0-9]*) interval=300 ;; esac
    [ $(( now - _SYSLOG_KEEP_LAST )) -ge "$interval" ] || return 0
    _SYSLOG_KEEP_LAST="$now"

    # GitHub #37: the settings of this round (see the header).
    _SYSLOG_KEEP_INTERVAL="$(_syslog_keep_cfg syslog_keep_interval_s 300)"
    case "$_SYSLOG_KEEP_INTERVAL" in ''|*[!0-9]*) _SYSLOG_KEEP_INTERVAL=300 ;; esac
    [ "$_SYSLOG_KEEP_INTERVAL" -ge 60 ] || _SYSLOG_KEEP_INTERVAL=60
    [ "$_SYSLOG_KEEP_INTERVAL" -le 86400 ] || _SYSLOG_KEEP_INTERVAL=86400
    if [ -n "${AICLI_SYSLOG_DST_DIR:-}" ]; then
        dir="$AICLI_SYSLOG_DST_DIR"
    else
        loc="$(_syslog_keep_cfg syslog_keep_location flash)"
        case "$loc" in
            off)
                return 0 ;;
            pool)
                dir="$(_syslog_keep_cfg syslog_keep_path '')"
                dir="${dir%/}"
                if ! _syslog_keep_pool_dir_ready "$dir"; then
                    if [ "$_SYSLOG_KEEP_SKIP_LOGGED" -eq 0 ]; then
                        _SYSLOG_KEEP_SKIP_LOGGED=1
                        _syslog_keep_note "WARN" "syslog-keep: the folder '${dir}' (syslog_keep_path) is not on a mounted pool; the system log is not kept until it is (this is logged once)"
                    fi
                    return 0
                fi
                ;;
            *)
                loc="flash"
                dir="/boot/logs" ;;
        esac
    fi
    dest="$dir/aicli-syslog-latest.txt"

    # A pool folder is never created (see the header); /boot/logs and a test folder are.
    if [ "$loc" != "pool" ] && ! mkdir -p "$dir" 2>/dev/null; then
        if [ "$_SYSLOG_KEEP_FAIL_LOGGED" -eq 0 ]; then
            _SYSLOG_KEEP_FAIL_LOGGED=1
            _syslog_keep_note "WARN" "syslog-keep: cannot create $dir; the system log is not kept (this is logged once)"
        fi
        return 0
    fi

    if [ "$_SYSLOG_KEEP_BOOT_DONE" -eq 0 ]; then
        _SYSLOG_KEEP_BOOT_DONE=1
        _syslog_keep_boot_archive "$dir" || true
    fi

    [ -f "$src" ] || return 0
    sig="$(stat -c '%s:%Y' "$src" 2>/dev/null)"
    [ -n "$sig" ] || return 0
    # The destination is part of the signature: a new location gets a copy at once.
    sig="$dest|$sig"
    [ "$sig" != "$_SYSLOG_KEEP_SIG" ] || return 0

    tmp="$dir/.aicli-syslog-latest.tmp.$$"
    if tail -c "$keep_bytes" "$src" > "$tmp" 2>/dev/null && mv -f "$tmp" "$dest" 2>/dev/null; then
        _SYSLOG_KEEP_SIG="$sig"
        _SYSLOG_KEEP_FAIL_LOGGED=0
        _SYSLOG_KEEP_SKIP_LOGGED=0
    else
        rm -f "$tmp" 2>/dev/null || true
        if [ "$_SYSLOG_KEEP_FAIL_LOGGED" -eq 0 ]; then
            _SYSLOG_KEEP_FAIL_LOGGED=1
            _syslog_keep_note "WARN" "syslog-keep: cannot write $dest (disk full or read only); the system log is not kept (this is logged once)"
        fi
    fi
    return 0
}
