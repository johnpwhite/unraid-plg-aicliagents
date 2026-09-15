#!/bin/bash
# backup_home.sh — HOME_BACKUP.md: the step functions behind the supervisor's
# `backup` op (aicli-supervisor.sh:_op_backup_home). SOURCED by
# aicli-supervisor.sh, so it shares that process's SUP_LOCK_FD, lifecycle_log,
# home_mount/home_persist_path (resolve_paths.sh) and job_ledger_* helpers
# (queue_helpers.sh) without re-deriving any of them. Also safe to source
# standalone (a bash unit test) — it self-sources both files when they were
# not already loaded.
#
# Entry point: _backup_home_execute <user> <reason> <job_id>
# Runs inside a background subshell spawned by _op_backup_home; every failure
# path ends with `exit 1` (ends the subshell cleanly — never the supervisor),
# success ends with `exit 0`. The exit code becomes the job's ledger exit via
# _wait_op_child + _job_finalize.
#
# What it does NOT do: the R2 pre-flight (target resolves, free space, entity
# not halted) — that runs once, synchronously, in
# StorageHandler::backupHome() BEFORE the job is even enqueued. This script
# re-checks only that the target is STILL there (a UD device could vanish
# between enqueue and the tick actually running).

SUPERVISOR_DIR="${SUPERVISOR_DIR:-/tmp/unraid-aicliagents/supervisor}"
STORAGE_DIR="${STORAGE_DIR:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage}"
AICLI_PLUGIN_ENTRY="${AICLI_PLUGIN_ENTRY:-/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php}"
EVENT_APPEND_PHP="${EVENT_APPEND_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/event-append.php}"

# Standalone-test safety net: when this file is sourced on its own (a bash
# unit test), pull in the path resolver + queue helpers it needs. Sourced by
# the real supervisor, both are already loaded — this is then a no-op.
if ! declare -f home_mount >/dev/null 2>&1; then
    _bk_resolve="$(dirname "${BASH_SOURCE[0]}")/resolve_paths.sh"
    # shellcheck disable=SC1090
    [ -f "$_bk_resolve" ] && source "$_bk_resolve" 2>/dev/null || true
    unset _bk_resolve
fi
if ! declare -f job_ledger_set_phase >/dev/null 2>&1; then
    _bk_qh="$(dirname "${BASH_SOURCE[0]}")/../supervisor/queue_helpers.sh"
    # shellcheck disable=SC1090
    [ -f "$_bk_qh" ] && source "$_bk_qh" 2>/dev/null || true
    unset _bk_qh
fi
if ! declare -f lifecycle_log >/dev/null 2>&1; then
    lifecycle_log() { true; }
fi
if ! declare -f log_info >/dev/null 2>&1; then
    log_info()  { printf '%s [INFO ] [backup_home] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
    log_warn()  { printf '%s [WARN ] [backup_home] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
    log_error() { printf '%s [ERROR] [backup_home] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
fi

# ---------------------------------------------------------------------------
# Options sidecar (StorageHandler::backupHome writes it; SupervisorService
# owns the path shape — mirrored here so a unit test can point SUPERVISOR_DIR
# at a tmpdir and this still finds it).
# ---------------------------------------------------------------------------

_backup_options_path() {
    printf '%s/backup-opts/%s.json' "$SUPERVISOR_DIR" "${1:-}"
}

# _backup_delete_options <job_id> — best-effort cleanup once the job is terminal.
_backup_delete_options() {
    rm -f "$(_backup_options_path "${1:-}")" 2>/dev/null || true
}

# _backup_load_options <job_id> — reads the sidecar via
# SupervisorService::readBackupOptions() (the single PHP source of truth for
# its shape) and fills the globals BK_TARGET, BK_QUIESCE, BK_KEEP, BK_NUDGE
# (each a scalar) and BK_EXCLUDES (an array). Returns 1 when the sidecar is
# missing/corrupt or has no target.
_backup_load_options() {
    local job_id="${1:-}"
    BK_TARGET=""; BK_QUIESCE="cold"; BK_KEEP=5; BK_NUDGE="0"; BK_EXCLUDES=()
    [ -n "$job_id" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    local out
    out="$(AICLI_BK_JOB="$job_id" AICLI_PLUGIN_ENTRY="$AICLI_PLUGIN_ENTRY" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"] = "/usr/local/emhttp";
        require_once (string)getenv("AICLI_PLUGIN_ENTRY");
        $jobId = (string)getenv("AICLI_BK_JOB");
        $opts = \AICliAgents\Services\SupervisorService::readBackupOptions($jobId);
        if (empty($opts)) { exit(1); }
        echo ($opts["target"] ?? "") . "\t" . ($opts["quiesce"] ?? "cold") . "\t"
            . (int)($opts["keep"] ?? 5) . "\t" . (!empty($opts["nudge_working"]) ? "1" : "0") . "\n";
        foreach ((array)($opts["excludes"] ?? []) as $e) {
            if (is_string($e) && $e !== "") echo $e . "\n";
        }
    ' 2>/dev/null)"
    local rc=$?
    [ "$rc" -eq 0 ] && [ -n "$out" ] || return 1

    local first_line
    first_line="$(printf '%s\n' "$out" | head -1)"
    IFS=$'\t' read -r BK_TARGET BK_QUIESCE BK_KEEP BK_NUDGE <<< "$first_line"
    case "$BK_QUIESCE" in cold|warm) : ;; *) BK_QUIESCE="cold" ;; esac
    case "$BK_KEEP" in ''|*[!0-9]*) BK_KEEP=5 ;; esac
    [ "$BK_KEEP" -ge 1 ] || BK_KEEP=1

    local line
    while IFS= read -r line; do
        [ -n "$line" ] && BK_EXCLUDES+=("$line")
    done < <(printf '%s\n' "$out" | tail -n +2)

    [ -n "$BK_TARGET" ]
}

# ---------------------------------------------------------------------------
# Hosting mode — the bash mirror of StoragePathResolver::homeHostingMode().
# ---------------------------------------------------------------------------

# _backup_hosting_mode <user> -> "layers" | "direct"
_backup_hosting_mode() {
    local id="${1:-}" persist
    persist="$(home_persist_path "$id" 2>/dev/null)"
    if [ -n "$persist" ] && compgen -G "${persist}/home_${id}_*.sqsh" > /dev/null 2>&1; then
        printf 'layers'
    else
        printf 'direct'
    fi
}

# ---------------------------------------------------------------------------
# Close / relaunch — PHP bridges into UpgradeRelaunchService. $user travels
# via env, NEVER spliced into the php -r script body (SECURITY, matches
# _close_home_for_consolidate / _relaunch_home_sessions above).
# ---------------------------------------------------------------------------

# _backup_close_home <user> -> the closed-set JSON array (echoed), "[]" on
# any failure or when php is unavailable. Never aborts the caller.
_backup_close_home() {
    local user="${1:-}"
    case "$user" in ''|*[!A-Za-z0-9._-]*) printf '[]'; return 0 ;; esac
    case "$user" in *..*) printf '[]'; return 0 ;; esac
    command -v php >/dev/null 2>&1 || { printf '[]'; return 0; }
    AICLI_BK_CLOSE_USER="$user" AICLI_PLUGIN_ENTRY="$AICLI_PLUGIN_ENTRY" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"] = "/usr/local/emhttp";
        require_once (string)getenv("AICLI_PLUGIN_ENTRY");
        $u = (string)getenv("AICLI_BK_CLOSE_USER");
        if (method_exists("\AICliAgents\Services\UpgradeRelaunchService", "closeHomeSet")) {
            $closed = \AICliAgents\Services\UpgradeRelaunchService::closeHomeSet($u);
            echo json_encode(is_array($closed) ? $closed : []);
        } else {
            echo "[]";
        }
    ' 2>/dev/null || printf '[]'
}

# _backup_relaunch_home <user> <nudge:0|1> [origin] — relaunch the closed set,
# resumed, and (when nudge=1) send one Continue nudge to every entry the close
# phase recorded working:true, once its pane accepts input. Drops SUP_LOCK_FD
# first (see _relaunch_home_sessions) — relaunchHomeSet spawns long-lived
# ttyd+tmux. [origin] defaults to "backup_relaunch" (HOME_BACKUP.md); passed
# through to relaunchHomeSet()'s `started` event tag so the drawer's
# stopped->started handler skips it (see TerminalService's continuedBy map).
# HOME_RESTORE.md's _restore_relaunch_home is a thin wrapper over this same
# function with origin "restore_relaunch" — the close/relaunch mechanics are
# identical, only the tag differs.
_backup_relaunch_home() {
    local user="${1:-}" nudge="${2:-0}" origin="${3:-backup_relaunch}"
    case "$user" in ''|*[!A-Za-z0-9._-]*) return 0 ;; esac
    case "$user" in *..*) return 0 ;; esac
    command -v php >/dev/null 2>&1 || return 0
    ( [ -n "${SUP_LOCK_FD:-}" ] && exec {SUP_LOCK_FD}>&- 2>/dev/null
      AICLI_BK_RELAUNCH_USER="$user" AICLI_BK_NUDGE="$nudge" AICLI_BK_ORIGIN="$origin" AICLI_PLUGIN_ENTRY="$AICLI_PLUGIN_ENTRY" \
      php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"] = "/usr/local/emhttp";
        require_once (string)getenv("AICLI_PLUGIN_ENTRY");
        $u = (string)getenv("AICLI_BK_RELAUNCH_USER");
        $nudgeOn = getenv("AICLI_BK_NUDGE") === "1";
        $origin = (string)getenv("AICLI_BK_ORIGIN");
        if ($origin === "") { $origin = "backup_relaunch"; }
        if (method_exists("\AICliAgents\Services\UpgradeRelaunchService", "relaunchHomeSet")) {
            $nudger = null; $ready = null;
            if ($nudgeOn && class_exists("\AICliAgents\Services\TmuxService")) {
                $nudger = ["\AICliAgents\Services\TmuxService", "submitContinueNudge"];
                $ready  = ["\AICliAgents\Services\TmuxService", "paneAcceptsInput"];
            }
            \AICliAgents\Services\UpgradeRelaunchService::relaunchHomeSet(
                $u, null, null, null, null, $nudger, $ready, null, $origin
            );
        }
    ' ) 2>/dev/null || true
    declare -f _publish_storage_status >/dev/null 2>&1 && _publish_storage_status
}

# _backup_bake_home <user> — bake the home's ZRAM upper into a durable .sqsh
# layer (R5, layer-stack homes only). A separate function (not inlined into
# the orchestrator) so a unit test can stub it instead of running the real
# storagectl.sh against fixture layers. Returns storagectl's exit status.
_backup_bake_home() {
    local user="${1:-}"
    local persist; persist="$(home_persist_path "$user" 2>/dev/null)"
    bash "${STORAGE_DIR}/storagectl.sh" bake --type home --id "$user" --persist "$persist" >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# Ledger — durable storage.backup.started|finished|failed events, via the
# existing generic CLI tee (event-append.php) rather than a bespoke bridge.
# ---------------------------------------------------------------------------

# _backup_ledger <kind> <user> <summary> [data_json]
# _backup_write_last_record <user> <kind> <summary> <data_json> — durable per-user
# record of the last run (finished or failed) at
# /boot/config/plugins/unraid-aicliagents/backup-last-<user>.json, so the
# Settings card and Health know the outcome and the target even when the run
# used a target override or the setting changed afterwards. Atomic (tmp + mv).
_backup_write_last_record() {
    local user="${1:-}" kind="${2:-}" summary="${3:-}" data_json="${4:-{}}"
    [ -n "$user" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    local dir="/boot/config/plugins/unraid-aicliagents" tmp
    [ -d "$dir" ] || return 0
    tmp="$dir/.backup-last-$user.$$.tmp"
    if AICLI_BK_REC_USER="$user" AICLI_BK_REC_KIND="$kind" AICLI_BK_REC_SUMMARY="$summary" \
       AICLI_BK_REC_DATA="$data_json" AICLI_BK_REC_TARGET="${BK_TARGET:-}" AICLI_BK_REC_WARM="${warm:-0}" \
       php -d display_errors=0 -r '
        $data = json_decode((string)getenv("AICLI_BK_REC_DATA"), true);
        $rec = [
            "user"    => (string)getenv("AICLI_BK_REC_USER"),
            "ok"      => getenv("AICLI_BK_REC_KIND") === "storage.backup.finished",
            "at"      => gmdate("Y-m-d\TH:i:s\Z"),
            "target"  => (string)getenv("AICLI_BK_REC_TARGET"),
            "warm"    => getenv("AICLI_BK_REC_WARM") === "1",
            "summary" => (string)getenv("AICLI_BK_REC_SUMMARY"),
            "data"    => is_array($data) ? $data : [],
        ];
        echo json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' > "$tmp" 2>/dev/null; then
        mv -f "$tmp" "$dir/backup-last-$user.json" 2>/dev/null || rm -f "$tmp"
    else
        rm -f "$tmp" 2>/dev/null
    fi
}
_backup_ledger() {
    local kind="${1:-}" user="${2:-}" summary="${3:-}" data_json="${4:-{}}"
    case "$kind" in
        storage.backup.finished|storage.backup.failed) _backup_write_last_record "$user" "$kind" "$summary" "$data_json" ;;
    esac
    command -v php >/dev/null 2>&1 || return 0
    [ -f "$EVENT_APPEND_PHP" ] || return 0
    php -d display_errors=0 "$EVENT_APPEND_PHP" \
        --kind="$kind" \
        --subject="$(printf '{"user":"%s"}' "$user")" \
        --summary="$summary" \
        --data="$data_json" >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# Snapshot layout: <target>/aicli-home-backup/<user>/<UTC ts>/{tree/,backup.json}
# plus a `latest` symlink. Retention keeps the newest N, oldest first removed,
# never the one `latest` points to (which is always the newest anyway).
# ---------------------------------------------------------------------------

_backup_base_dir() { printf '%s/aicli-home-backup/%s' "${1:-}" "${2:-}"; }

# _backup_new_snapshot_dir <target> <user> -> a fresh UTC-timestamped path.
# The name has one-second resolution. When a snapshot of that second already
# exists (a safety snapshot minted right after the backup it restores, or two
# backups in one second), wait for the next second so the new snapshot never
# lands on top of an existing one.
_backup_new_snapshot_dir() {
    local base ts n=0
    base="$(_backup_base_dir "${1:-}" "${2:-}")"
    while :; do
        ts="$(date -u +%Y%m%dT%H%M%SZ)"
        [ -e "$base/$ts" ] && [ "$n" -lt 5 ] || break
        n=$((n + 1)); sleep 1
    done
    printf '%s/%s' "$base" "$ts"
}

# _backup_latest_snapshot_dir <target> <user> -> the `latest` symlink's
# resolved absolute path, or "" when there is no previous snapshot.
_backup_latest_snapshot_dir() {
    local link; link="$(_backup_base_dir "${1:-}" "${2:-}")/latest"
    [ -e "$link" ] || { printf ''; return 0; }
    if [ -L "$link" ]; then
        readlink -f "$link" 2>/dev/null
    else
        printf '%s' "$link"
    fi
}

# _backup_write_excludes_file <out_file> <pattern>...
_backup_write_excludes_file() {
    local out_file="${1:-}"; shift || true
    : > "$out_file"
    local p
    for p in "$@"; do
        printf '%s\n' "$p" >> "$out_file"
    done
}

# _backup_rsync_copy <src_home> <tree_dst> <prev_snapshot_dir_or_empty> <exclude_file>
# Sets BK_RSYNC_FILES / BK_RSYNC_BYTES from rsync --stats. Returns rsync's exit
# status (0 = ok).
_backup_rsync_copy() {
    local src="${1:-}" dst="${2:-}" prev="${3:-}" exclude_file="${4:-}"
    BK_RSYNC_FILES=0; BK_RSYNC_BYTES=0
    mkdir -p "$dst" 2>/dev/null || return 1

    local link_dest_arg=()
    if [ -n "$prev" ] && [ -d "${prev}/tree" ]; then
        link_dest_arg=(--link-dest="${prev}/tree")
    fi

    local out rc
    out="$(ionice -c3 nice -n19 rsync -a --numeric-ids --delete --stats \
        --exclude-from="$exclude_file" "${link_dest_arg[@]}" \
        "${src%/}/" "${dst%/}/" 2>&1)"
    rc=$?

    # file_count is the size of the snapshot tree (every regular file rsync
    # lists), not the number it had to transfer: with --link-dest a second run
    # transfers a handful of files while the tree still holds every file.
    local files bytes transferred
    files="$(printf '%s\n' "$out" | grep -oP 'Number of files:\s*[0-9,]+\s*\(reg:\s*\K[0-9,]+' | tr -d ',' | head -1)"
    transferred="$(printf '%s\n' "$out" | grep -oP 'Number of (regular )?files transferred:\s*\K[0-9,]+' | tr -d ',' | head -1)"
    [ -n "$files" ] || files="$transferred"
    BK_RSYNC_TRANSFERRED="${transferred:-0}"
    bytes="$(printf '%s\n' "$out" | grep -oP 'Total (transferred )?file size:\s*\K[0-9,]+' | tr -d ',' | head -1)"
    [ -n "$files" ] && BK_RSYNC_FILES="$files"
    [ -n "$bytes" ] && BK_RSYNC_BYTES="$bytes"

    if [ "$rc" -ne 0 ]; then
        # Bash-native tail (no external `tail` dependency/dialect to worry about).
        printf '%s\n' "${out: -2000}" >&2
    fi
    return "$rc"
}

# _backup_verify <src_home> <tree_dst> <exclude_file> — a second, --checksum
# dry-run pass must report ZERO differences. Returns 0 = verified, 1 = differs.
_backup_verify() {
    local src="${1:-}" dst="${2:-}" exclude_file="${3:-}"
    local diff
    diff="$(rsync -a --dry-run --checksum --itemize-changes \
        --exclude-from="$exclude_file" "${src%/}/" "${dst%/}/" 2>/dev/null | grep -v '^$')"
    [ -z "$diff" ]
}

# _backup_update_latest <target> <user> <snap_dir> — atomic-enough symlink
# swap (ln -sfn replaces the old target in one syscall).
_backup_update_latest() {
    local base; base="$(_backup_base_dir "${1:-}" "${2:-}")"
    mkdir -p "$base" 2>/dev/null || true
    ln -sfn "$(basename "${3:-}")" "$base/latest" 2>/dev/null || true
}

# _backup_prune <target> <user> <keep> — remove the oldest snapshot dirs
# beyond <keep>, never the newest (== the one `latest` points to).
#
# Pure bash globbing + `sort`, deliberately NOT `find -regex`: the UTC
# timestamp directory name (YYYYMMDDTHHMMSSZ) sorts correctly as a plain
# string, so nothing more than a glob + a shape filter is needed, and it
# avoids depending on a particular `find` build's regex dialect.
_backup_prune() {
    local base keep="${3:-5}"
    base="$(_backup_base_dir "${1:-}" "${2:-}")"
    [ -d "$base" ] || return 0
    case "$keep" in ''|*[!0-9]*) keep=5 ;; esac
    [ "$keep" -ge 1 ] || keep=1

    local names=() entry name
    shopt -s nullglob
    for entry in "$base"/*/; do
        entry="${entry%/}"
        name="$(basename "$entry")"
        case "$name" in
            [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z)
                names+=("$name")
                ;;
        esac
    done
    shopt -u nullglob

    local dirs=() line
    while IFS= read -r line; do
        [ -n "$line" ] && dirs+=("$base/$line")
    done < <(printf '%s\n' "${names[@]}" | sort)

    local total="${#dirs[@]}"
    local remove=$(( total - keep ))
    [ "$remove" -gt 0 ] || return 0
    local i
    for (( i = 0; i < remove; i++ )); do
        rm -rf "${dirs[$i]}" 2>/dev/null || true
    done
}

# _backup_write_manifest <snap_dir> <user> <hosting> <quiesce> <warm:0|1> \
#     <closed_json> <started_iso> <finished_iso> <file_count> <bytes> \
#     <excludes_file> [label]
# Writes backup.json atomically (tmp + rename), via php for correct JSON
# encoding of the sessions array. Returns php's exit status. [label] is
# optional and additive (HOME_RESTORE.md): a restore's own safety snapshot
# passes "pre-restore" so the snapshot list can mark it; a normal backup
# passes nothing and the field is omitted, unchanged from before.
_backup_write_manifest() {
    local snap_dir="${1:-}" user="${2:-}" hosting="${3:-}" quiesce="${4:-}" warm="${5:-0}"
    local closed_json="${6:-[]}" started_iso="${7:-}" finished_iso="${8:-}"
    local file_count="${9:-0}" bytes="${10:-0}" excludes_file="${11:-}" label="${12:-}"
    command -v php >/dev/null 2>&1 || return 1
    AICLI_BK_SNAP="$snap_dir" AICLI_BK_USER="$user" AICLI_BK_HOSTING="$hosting" \
    AICLI_BK_QUIESCE="$quiesce" AICLI_BK_WARM="$warm" AICLI_BK_CLOSED="$closed_json" \
    AICLI_BK_STARTED="$started_iso" AICLI_BK_FINISHED="$finished_iso" \
    AICLI_BK_FILES="$file_count" AICLI_BK_BYTES="$bytes" AICLI_BK_EXCLUDES_FILE="$excludes_file" \
    AICLI_BK_LABEL="$label" \
    php -d display_errors=0 -r '
        $excludes = [];
        $ef = (string)getenv("AICLI_BK_EXCLUDES_FILE");
        if ($ef !== "" && is_file($ef)) {
            foreach (file($ef, FILE_IGNORE_NEW_LINES) ?: [] as $l) { if ($l !== "") $excludes[] = $l; }
        }
        $closed = json_decode((string)getenv("AICLI_BK_CLOSED"), true);
        if (!is_array($closed)) { $closed = []; }
        $manifest = [
            "version"      => 1,
            "user"         => (string)getenv("AICLI_BK_USER"),
            "hosting_mode" => (string)getenv("AICLI_BK_HOSTING"),
            "quiesce"      => (string)getenv("AICLI_BK_QUIESCE"),
            "warm"         => getenv("AICLI_BK_WARM") === "1",
            "started_at"   => (string)getenv("AICLI_BK_STARTED"),
            "finished_at"  => (string)getenv("AICLI_BK_FINISHED"),
            "file_count"   => (int)getenv("AICLI_BK_FILES"),
            "bytes"        => (int)getenv("AICLI_BK_BYTES"),
            "excludes"     => $excludes,
            "sessions"     => $closed,
        ];
        $label = (string)getenv("AICLI_BK_LABEL");
        if ($label !== "") { $manifest["label"] = $label; }
        $dest = rtrim((string)getenv("AICLI_BK_SNAP"), "/") . "/backup.json";
        $tmp = $dest . ".tmp." . getmypid();
        if (file_put_contents($tmp, json_encode($manifest, JSON_PRETTY_PRINT)) === false) { exit(1); }
        if (!rename($tmp, $dest)) { @unlink($tmp); exit(1); }
        exit(0);
    ' 2>/dev/null
    return $?
}

# ---------------------------------------------------------------------------
# Orchestrator — called by _op_backup_home (aicli-supervisor.sh) inside a
# background subshell. Every exit here ends that subshell only.
# ---------------------------------------------------------------------------

# _backup_home_execute <user> <reason> <job_id>
_backup_home_execute() {
    local user="${1:-}" reason="${2:-user_backup_home}" job_id="${3:-}"
    local entity="home/${user}"

    if ! _backup_load_options "$job_id"; then
        lifecycle_log "error" "supervisor" "backup_no_options" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: no backup options" '{"cause":"no_options"}'
        exit 1
    fi

    job_ledger_set_phase "$job_id" "pre-flight" 2>/dev/null || true
    _backup_ledger "storage.backup.started" "$user" "home backup of $user started" \
        "$(printf '{"target":"%s","quiesce":"%s","reason":"%s"}' "$BK_TARGET" "$BK_QUIESCE" "$reason")"

    # Defensive re-check: the full R2 pre-flight already ran, synchronously,
    # in StorageHandler::backupHome() before this job was even enqueued. Only
    # re-verify the target is STILL there (a UD device can vanish between the
    # click and this tick actually running).
    if [ ! -d "$BK_TARGET" ] || [ ! -w "$BK_TARGET" ]; then
        lifecycle_log "error" "supervisor" "backup_preflight_failed" \
            "{\"entity\":\"$entity\",\"reason\":\"target_unavailable\"}" 2>/dev/null || true
        _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: target unavailable" '{"cause":"target_unavailable"}'
        exit 1
    fi

    local home_mnt hosting warm=0
    home_mnt="$(home_mount "$user" 2>/dev/null)"
    hosting="$(_backup_hosting_mode "$user")"
    [ "$BK_QUIESCE" = "warm" ] && warm=1

    # R3/R4: cold mode closes every session, recording {sessionId, agentId,
    # workspacePath, hadResume, working}. R7: warm mode never closes anything.
    local closed_json="[]"
    if [ "$warm" -eq 0 ]; then
        job_ledger_set_phase "$job_id" "closing" 2>/dev/null || true
        closed_json="$(_backup_close_home "$user")"
        [ -n "$closed_json" ] || closed_json="[]"
    fi

    # R5: bake the upper when the home is a layer stack — cold mode only
    # (R7: warm mode never bakes, even on a layer-stack home).
    if [ "$warm" -eq 0 ] && [ "$hosting" = "layers" ]; then
        job_ledger_set_phase "$job_id" "baking" 2>/dev/null || true
        if ! _backup_bake_home "$user"; then
            lifecycle_log "error" "supervisor" "backup_bake_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
            _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: bake failed" '{"cause":"bake_failed"}'
            if [ "$warm" -eq 0 ]; then
                job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
                _backup_relaunch_home "$user" "$BK_NUDGE"
            fi
            exit 1
        fi
    fi

    job_ledger_set_phase "$job_id" "copying" 2>/dev/null || true
    local snap_dir prev_dir excludes_file started_iso
    snap_dir="$(_backup_new_snapshot_dir "$BK_TARGET" "$user")"
    prev_dir="$(_backup_latest_snapshot_dir "$BK_TARGET" "$user")"
    if ! mkdir -p "$snap_dir" 2>/dev/null; then
        _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: could not create the snapshot directory" '{"cause":"mkdir_failed"}'
        if [ "$warm" -eq 0 ]; then
            job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
            _backup_relaunch_home "$user" "$BK_NUDGE"
        fi
        exit 1
    fi
    chmod 0700 "$snap_dir" 2>/dev/null || true
    excludes_file="${snap_dir}.excludes"
    _backup_write_excludes_file "$excludes_file" "${BK_EXCLUDES[@]}"
    started_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if ! _backup_rsync_copy "$home_mnt" "$snap_dir/tree" "$prev_dir" "$excludes_file"; then
        rm -rf "$snap_dir" 2>/dev/null || true
        rm -f "$excludes_file" 2>/dev/null || true
        lifecycle_log "error" "supervisor" "backup_rsync_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: copy failed" '{"cause":"rsync_failed"}'
        if [ "$warm" -eq 0 ]; then
            job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
            _backup_relaunch_home "$user" "$BK_NUDGE"
        fi
        exit 1
    fi

    job_ledger_set_phase "$job_id" "verifying" 2>/dev/null || true
    if ! _backup_verify "$home_mnt" "$snap_dir/tree" "$excludes_file"; then
        rm -rf "$snap_dir" 2>/dev/null || true
        rm -f "$excludes_file" 2>/dev/null || true
        lifecycle_log "error" "supervisor" "backup_verify_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _backup_ledger "storage.backup.failed" "$user" "home backup of $user failed: verify found differences" '{"cause":"verify_failed"}'
        if [ "$warm" -eq 0 ]; then
            job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
            _backup_relaunch_home "$user" "$BK_NUDGE"
        fi
        exit 1
    fi

    local finished_iso; finished_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if ! _backup_write_manifest "$snap_dir" "$user" "$hosting" "$BK_QUIESCE" "$warm" "$closed_json" \
            "$started_iso" "$finished_iso" "${BK_RSYNC_FILES:-0}" "${BK_RSYNC_BYTES:-0}" "$excludes_file"; then
        log_warn "Home backup for $entity: could not write backup.json (the snapshot tree is still on disk at $snap_dir)"
    fi
    rm -f "$excludes_file" 2>/dev/null || true

    # R9: only after a SUCCESSFUL snapshot does `latest` move and pruning run.
    _backup_update_latest "$BK_TARGET" "$user" "$snap_dir"
    _backup_prune "$BK_TARGET" "$user" "$BK_KEEP"

    if [ "$warm" -eq 0 ]; then
        job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
        _backup_relaunch_home "$user" "$BK_NUDGE"
    fi

    _backup_ledger "storage.backup.finished" "$user" \
        "home backup of $user finished (${BK_RSYNC_FILES:-0} files, $(( ${BK_RSYNC_BYTES:-0} / 1048576 )) MB) → $snap_dir" \
        "$(printf '{"path":"%s","bytes":%s,"files":%s}' "$snap_dir" "${BK_RSYNC_BYTES:-0}" "${BK_RSYNC_FILES:-0}")"
    exit 0
}

# ===========================================================================
# docs/specs/HOME_RESTORE.md — put a backup snapshot back into a user's home.
# Reuses the step functions above (hosting mode, close, bake, snapshot copy/
# verify/manifest/prune, relaunch) — a restore's own steps are only the
# rsync-back-into-the-home direction and the safety-snapshot-then-copy order.
# ===========================================================================

# ---------------------------------------------------------------------------
# Options sidecar — mirrors _backup_load_options above but reads
# SupervisorService::readRestoreOptions() into a distinct set of globals
# (RS_*), from a distinct sidecar directory (restore-opts/, not backup-opts/)
# so a backup job id and a restore job id can never collide.
# ---------------------------------------------------------------------------

_restore_options_path() {
    printf '%s/restore-opts/%s.json' "$SUPERVISOR_DIR" "${1:-}"
}

# _restore_delete_options <job_id> — best-effort cleanup once the job is terminal.
_restore_delete_options() {
    rm -f "$(_restore_options_path "${1:-}")" 2>/dev/null || true
}

# _restore_load_options <job_id> — fills RS_SNAPSHOT, RS_MODE ('replace'|
# 'merge'), RS_SAFETY ('0'|'1'), RS_TARGET (the safety-snapshot target, or
# '' when RS_SAFETY=0), RS_KEEP and RS_EXCLUDES (an array). Returns 1 when
# the sidecar is missing/corrupt or has no snapshot.
_restore_load_options() {
    local job_id="${1:-}"
    RS_SNAPSHOT=""; RS_MODE="replace"; RS_SAFETY="1"; RS_TARGET=""; RS_KEEP=5; RS_EXCLUDES=()
    [ -n "$job_id" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    local out
    out="$(AICLI_RS_JOB="$job_id" AICLI_PLUGIN_ENTRY="$AICLI_PLUGIN_ENTRY" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"] = "/usr/local/emhttp";
        require_once (string)getenv("AICLI_PLUGIN_ENTRY");
        $jobId = (string)getenv("AICLI_RS_JOB");
        $opts = \AICliAgents\Services\SupervisorService::readRestoreOptions($jobId);
        if (empty($opts)) { exit(1); }
        echo ($opts["snapshot"] ?? "") . "\t" . ($opts["mode"] ?? "replace") . "\t"
            . (!empty($opts["safety_snapshot"]) ? "1" : "0") . "\t" . ($opts["target"] ?? "") . "\t"
            . (int)($opts["keep"] ?? 5) . "\n";
        foreach ((array)($opts["excludes"] ?? []) as $e) {
            if (is_string($e) && $e !== "") echo $e . "\n";
        }
    ' 2>/dev/null)"
    local rc=$?
    [ "$rc" -eq 0 ] && [ -n "$out" ] || return 1

    local first_line
    first_line="$(printf '%s\n' "$out" | head -1)"
    IFS=$'\t' read -r RS_SNAPSHOT RS_MODE RS_SAFETY RS_TARGET RS_KEEP <<< "$first_line"
    case "$RS_MODE" in replace|merge) : ;; *) RS_MODE="replace" ;; esac
    case "$RS_SAFETY" in 0|1) : ;; *) RS_SAFETY="1" ;; esac
    case "$RS_KEEP" in ''|*[!0-9]*) RS_KEEP=5 ;; esac
    [ "$RS_KEEP" -ge 1 ] || RS_KEEP=1

    local line
    while IFS= read -r line; do
        [ -n "$line" ] && RS_EXCLUDES+=("$line")
    done < <(printf '%s\n' "$out" | tail -n +2)

    [ -n "$RS_SNAPSHOT" ]
}

# _restore_relaunch_home <user> <nudge:0|1> — thin wrapper over
# _backup_relaunch_home with origin "restore_relaunch" (HOME_RESTORE.md R8),
# so the `started` event's continuedBy tag reads "restore", not "backup".
_restore_relaunch_home() {
    _backup_relaunch_home "${1:-}" "${2:-0}" "restore_relaunch"
}

# ---------------------------------------------------------------------------
# Safety snapshot — HOME_RESTORE.md: a normal cold snapshot of the CURRENT
# home, labelled "pre-restore", taken to the SAME target/base dir a regular
# backup would use for this user (so it appears in that same snapshot list
# and counts toward the SAME retention). Reuses every backup plumbing
# function directly — only the label and the caller are new.
# ---------------------------------------------------------------------------

# _restore_safety_snapshot <user> <home_mnt> — sets RS_SAFETY_PATH to the new
# snapshot dir on success (empty on any failure or when disabled). Returns
# 0 = made (or disabled), 1 = failed (the restore must not proceed).
_restore_safety_snapshot() {
    local user="${1:-}" home_mnt="${2:-}"
    RS_SAFETY_PATH=""
    [ "$RS_SAFETY" = "1" ] || return 0
    [ -n "$RS_TARGET" ] && [ -d "$RS_TARGET" ] && [ -w "$RS_TARGET" ] || return 1

    local snap_dir prev_dir excludes_file started_iso finished_iso
    snap_dir="$(_backup_new_snapshot_dir "$RS_TARGET" "$user")"
    prev_dir="$(_backup_latest_snapshot_dir "$RS_TARGET" "$user")"
    mkdir -p "$snap_dir" 2>/dev/null || return 1
    chmod 0700 "$snap_dir" 2>/dev/null || true
    excludes_file="${snap_dir}.excludes"
    _backup_write_excludes_file "$excludes_file" "${RS_EXCLUDES[@]}"
    started_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if ! _backup_rsync_copy "$home_mnt" "$snap_dir/tree" "$prev_dir" "$excludes_file"; then
        rm -rf "$snap_dir" 2>/dev/null || true
        rm -f "$excludes_file" 2>/dev/null || true
        return 1
    fi
    if ! _backup_verify "$home_mnt" "$snap_dir/tree" "$excludes_file"; then
        rm -rf "$snap_dir" 2>/dev/null || true
        rm -f "$excludes_file" 2>/dev/null || true
        return 1
    fi
    finished_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    _backup_write_manifest "$snap_dir" "$user" "$(_backup_hosting_mode "$user")" "cold" "0" "[]" \
        "$started_iso" "$finished_iso" "${BK_RSYNC_FILES:-0}" "${BK_RSYNC_BYTES:-0}" "$excludes_file" "pre-restore"
    rm -f "$excludes_file" 2>/dev/null || true

    # A labelled safety snapshot is a normal snapshot for retention purposes
    # (HOME_RESTORE.md: "a labelled snapshot also counts for retention like
    # any other") — it becomes the new `latest` and prune runs exactly as a
    # regular backup's does.
    _backup_update_latest "$RS_TARGET" "$user" "$snap_dir"
    _backup_prune "$RS_TARGET" "$user" "${RS_KEEP:-5}"
    RS_SAFETY_PATH="$snap_dir"
    return 0
}

# ---------------------------------------------------------------------------
# Restoring — rsync the snapshot's tree/ back into the mounted home, and
# verify. Sets BK_RSYNC_FILES/BK_RSYNC_BYTES via _backup_rsync_copy's own
# --stats parsing (unused here but harmless to leave set).
# ---------------------------------------------------------------------------

# _restore_rsync_copy <snapshot_tree> <home_mnt> <mode:replace|merge>
# replace: --delete, the home ends up identical to the snapshot tree.
# merge:   no --delete, nothing in the home is removed.
_restore_rsync_copy() {
    local src_tree="${1:-}" dst_home="${2:-}" mode="${3:-replace}"
    local delete_arg=()
    [ "$mode" = "replace" ] && delete_arg=(--delete)
    local out rc
    out="$(ionice -c3 nice -n19 rsync -a --numeric-ids "${delete_arg[@]}" \
        "${src_tree%/}/" "${dst_home%/}/" 2>&1)"
    rc=$?
    if [ "$rc" -ne 0 ]; then
        printf '%s\n' "${out: -2000}" >&2
    fi
    return "$rc"
}

# _restore_verify <snapshot_tree> <home_mnt> <mode:replace|merge> — a second,
# --checksum dry-run pass. replace: must report ZERO differences (matching
# --delete, so a stray extra file in the home would also show up). merge: no
# --delete on the dry run either (nothing was meant to be removed); only a
# '>f' (content differs) line on a file the snapshot HOLDS is a real problem
# — an extra file the snapshot never had is expected to remain untouched.
# Returns 0 = verified, 1 = differs.
_restore_verify() {
    local src_tree="${1:-}" dst_home="${2:-}" mode="${3:-replace}"
    local delete_arg=()
    [ "$mode" = "replace" ] && delete_arg=(--delete)
    local diff
    diff="$(rsync -a --dry-run --checksum --itemize-changes "${delete_arg[@]}" \
        "${src_tree%/}/" "${dst_home%/}/" 2>/dev/null | grep -v '^$')"
    if [ "$mode" = "merge" ]; then
        diff="$(printf '%s\n' "$diff" | grep '^>f' || true)"
    fi
    [ -z "$diff" ]
}

# ---------------------------------------------------------------------------
# Ledger + durable per-user record — distinct file/kinds from backup's
# (restore-last-<user>.json, storage.restore.*), same atomic tmp+mv pattern.
# ---------------------------------------------------------------------------

# _restore_write_last_record <user> <ok:0|1> <snapshot> <mode> <safety_path> <cause> <summary>
_restore_write_last_record() {
    local user="${1:-}" ok="${2:-0}" snapshot="${3:-}" mode="${4:-}" safety_path="${5:-}" cause="${6:-}" summary="${7:-}"
    [ -n "$user" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    local dir="/boot/config/plugins/unraid-aicliagents" tmp
    [ -d "$dir" ] || return 0
    tmp="$dir/.restore-last-$user.$$.tmp"
    if AICLI_RS_REC_USER="$user" AICLI_RS_REC_OK="$ok" AICLI_RS_REC_SNAPSHOT="$snapshot" \
       AICLI_RS_REC_MODE="$mode" AICLI_RS_REC_SAFETY="$safety_path" AICLI_RS_REC_CAUSE="$cause" \
       AICLI_RS_REC_SUMMARY="$summary" \
       php -d display_errors=0 -r '
        $rec = [
            "user"                 => (string)getenv("AICLI_RS_REC_USER"),
            "ok"                   => getenv("AICLI_RS_REC_OK") === "1",
            "at"                   => gmdate("Y-m-d\TH:i:s\Z"),
            "snapshot"             => (string)getenv("AICLI_RS_REC_SNAPSHOT"),
            "mode"                 => (string)getenv("AICLI_RS_REC_MODE"),
            "safety_snapshot_path" => (string)getenv("AICLI_RS_REC_SAFETY"),
            "cause"                => (string)getenv("AICLI_RS_REC_CAUSE"),
            "summary"              => (string)getenv("AICLI_RS_REC_SUMMARY"),
        ];
        echo json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ' > "$tmp" 2>/dev/null; then
        mv -f "$tmp" "$dir/restore-last-$user.json" 2>/dev/null || rm -f "$tmp"
    else
        rm -f "$tmp" 2>/dev/null
    fi
}

# _restore_ledger <kind> <user> <summary> [data_json]
_restore_ledger() {
    local kind="${1:-}" user="${2:-}" summary="${3:-}" data_json="${4:-{}}"
    command -v php >/dev/null 2>&1 || return 0
    [ -f "$EVENT_APPEND_PHP" ] || return 0
    php -d display_errors=0 "$EVENT_APPEND_PHP" \
        --kind="$kind" \
        --subject="$(printf '{"user":"%s"}' "$user")" \
        --summary="$summary" \
        --data="$data_json" >/dev/null 2>&1 || true
}

# ---------------------------------------------------------------------------
# Orchestrator — called by _op_restore_home (aicli-supervisor.sh) inside a
# background subshell. Every exit here ends that subshell only. Steps (also
# the job-ledger "phase" tokens the tray reads): pre-flight, closing,
# safety-snapshot, restoring, verifying, baking, relaunching.
# ---------------------------------------------------------------------------

# _restore_home_execute <user> <reason> <job_id>
_restore_home_execute() {
    local user="${1:-}" reason="${2:-user_restore_home}" job_id="${3:-}"
    local entity="home/${user}"

    if ! _restore_load_options "$job_id"; then
        lifecycle_log "error" "supervisor" "restore_no_options" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: no restore options" '{"cause":"no_options"}'
        _restore_write_last_record "$user" "0" "" "" "" "no_options" "home restore of $user failed: no restore options"
        exit 1
    fi

    job_ledger_set_phase "$job_id" "pre-flight" 2>/dev/null || true
    _restore_ledger "storage.restore.started" "$user" "home restore of $user started" \
        "$(printf '{"snapshot":"%s","mode":"%s","reason":"%s"}' "$RS_SNAPSHOT" "$RS_MODE" "$reason")"

    # Defensive re-check: the full R3 pre-flight already ran, synchronously,
    # in StorageHandler::restoreHome() before this job was even enqueued. Only
    # re-verify the snapshot and the home mount are STILL there (either could
    # vanish between the click and this tick actually running).
    if [ ! -d "$RS_SNAPSHOT" ] || [ ! -d "$RS_SNAPSHOT/tree" ]; then
        lifecycle_log "error" "supervisor" "restore_preflight_failed" \
            "{\"entity\":\"$entity\",\"cause\":\"snapshot_unavailable\"}" 2>/dev/null || true
        _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: the snapshot is no longer available" '{"cause":"snapshot_unavailable"}'
        _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "" "snapshot_unavailable" "home restore of $user failed: the snapshot is no longer available"
        exit 1
    fi
    local home_mnt
    home_mnt="$(home_mount "$user" 2>/dev/null)"
    if [ -z "$home_mnt" ] || [ ! -d "$home_mnt" ]; then
        lifecycle_log "error" "supervisor" "restore_preflight_failed" \
            "{\"entity\":\"$entity\",\"cause\":\"home_unavailable\"}" 2>/dev/null || true
        _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: the home is no longer mounted" '{"cause":"home_unavailable"}'
        _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "" "home_unavailable" "home restore of $user failed: the home is no longer mounted"
        exit 1
    fi

    # R3/R4: a restore always closes every session first (cold only — there
    # is no warm restore). Reuses the SAME close phase a backup uses.
    job_ledger_set_phase "$job_id" "closing" 2>/dev/null || true
    _backup_close_home "$user" >/dev/null

    # R4: the safety snapshot (unless the operator turned it off). A failure
    # here means the restore does NOT proceed (the whole point was
    # reversibility) — but the sessions are still relaunched (R5).
    local safety_path=""
    if [ "$RS_SAFETY" = "1" ]; then
        job_ledger_set_phase "$job_id" "safety-snapshot" 2>/dev/null || true
        if ! _restore_safety_snapshot "$user" "$home_mnt"; then
            lifecycle_log "error" "supervisor" "restore_safety_snapshot_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
            _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: the safety snapshot could not be made" '{"cause":"safety_snapshot_failed"}'
            _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "" "safety_snapshot_failed" "home restore of $user failed: the safety snapshot could not be made"
            job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
            _restore_relaunch_home "$user" "1"
            exit 1
        fi
        safety_path="$RS_SAFETY_PATH"
    fi

    job_ledger_set_phase "$job_id" "restoring" 2>/dev/null || true
    if ! _restore_rsync_copy "$RS_SNAPSHOT/tree" "$home_mnt" "$RS_MODE"; then
        lifecycle_log "error" "supervisor" "restore_rsync_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: copy failed" \
            "$(printf '{"cause":"rsync_failed","safety_snapshot_path":"%s"}' "$safety_path")"
        _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "$safety_path" "rsync_failed" "home restore of $user failed: copy failed"
        job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
        _restore_relaunch_home "$user" "1"
        exit 1
    fi

    job_ledger_set_phase "$job_id" "verifying" 2>/dev/null || true
    if ! _restore_verify "$RS_SNAPSHOT/tree" "$home_mnt" "$RS_MODE"; then
        lifecycle_log "error" "supervisor" "restore_verify_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
        _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: verify found differences" \
            "$(printf '{"cause":"verify_failed","safety_snapshot_path":"%s"}' "$safety_path")"
        _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "$safety_path" "verify_failed" "home restore of $user failed: verify found differences"
        job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
        _restore_relaunch_home "$user" "1"
        exit 1
    fi

    # R4: bake when the home is a layer stack, so the restored state is
    # durable too (a directly hosted home has nothing to bake — the rsync
    # above IS the durable state already).
    local hosting; hosting="$(_backup_hosting_mode "$user")"
    if [ "$hosting" = "layers" ]; then
        job_ledger_set_phase "$job_id" "baking" 2>/dev/null || true
        if ! _backup_bake_home "$user"; then
            lifecycle_log "error" "supervisor" "restore_bake_failed" "{\"entity\":\"$entity\"}" 2>/dev/null || true
            _restore_ledger "storage.restore.failed" "$user" "home restore of $user failed: bake failed" \
                "$(printf '{"cause":"bake_failed","safety_snapshot_path":"%s"}' "$safety_path")"
            _restore_write_last_record "$user" "0" "$RS_SNAPSHOT" "$RS_MODE" "$safety_path" "bake_failed" "home restore of $user failed: bake failed"
            job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
            _restore_relaunch_home "$user" "1"
            exit 1
        fi
    fi

    job_ledger_set_phase "$job_id" "relaunching" 2>/dev/null || true
    _restore_relaunch_home "$user" "1"

    _restore_ledger "storage.restore.finished" "$user" "home restore of $user finished from $RS_SNAPSHOT" \
        "$(printf '{"snapshot":"%s","mode":"%s","safety_snapshot_path":"%s"}' "$RS_SNAPSHOT" "$RS_MODE" "$safety_path")"
    _restore_write_last_record "$user" "1" "$RS_SNAPSHOT" "$RS_MODE" "$safety_path" "" "home restore of $user finished from $RS_SNAPSHOT"
    exit 0
}
