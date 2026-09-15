#!/usr/bin/env bash
# Publish AICliAgents to the local Factory host from an Unraid host terminal.
#
# The controller validates the release while agents are still running, then
# starts a detached worker and follows its durable log.  The worker uses the
# installed plugin's canonical stop path (resume capture, graceful termination,
# home persistence, unmount), followed by the workspace's canonical ci/publish.
# If the browser terminal disconnects, the worker continues; reconnect with
# --monitor.
set -Eeuo pipefail

readonly DEFAULT_WS_ROOT="/mnt/cache/DevelopmentProjects/unraid-extensions"
readonly DEFAULT_RUN_ROOT="/var/log/aicliagents-factory-publish"
readonly DEFAULT_SERVER="192.168.1.4"
readonly DEFAULT_KEY="/root/.ssh/id_ed25519"
readonly PLUGIN="aicliagents"
readonly PLG="unraid-aicliagents.plg"
readonly MESSAGE="Fix: make Factory repair fail closed on current storage (#93 #96)"
readonly SELF="$(readlink -f "${BASH_SOURCE[0]}")"

WS_ROOT="${AICLI_FACTORY_WS_ROOT:-$DEFAULT_WS_ROOT}"
RUN_ROOT="${AICLI_FACTORY_RUN_ROOT:-$DEFAULT_RUN_ROOT}"
SERVER="${AICLI_FACTORY_SERVER:-$DEFAULT_SERVER}"
SSH_KEY="${AICLI_FACTORY_SSH_KEY:-$DEFAULT_KEY}"
PLUGIN_DIR="$WS_ROOT/unraid-plg-$PLUGIN"
INDEX_DIR="$WS_ROOT/unraid-community-applications-index"
PUBLISH="${AICLI_FACTORY_PUBLISH:-$WS_ROOT/ci/publish}"
STOP_SCRIPT="${AICLI_FACTORY_STOP_SCRIPT:-/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/user/stop-plugin.sh}"
RESUME_CAPTURE="${AICLI_FACTORY_RESUME_CAPTURE:-$PLUGIN_DIR/bin/capture-codex-resumes-before-factory.php}"

YES=0
MODE="run"
RUN_ID=""
RUN_DIR=""

usage() {
    cat <<'USAGE'
Usage:
  publish-to-factory.sh [--yes]
  publish-to-factory.sh --preflight
  publish-to-factory.sh --monitor [RUN_ID]

Default flow:
  1. Refuse to run inside an AICliAgents session.
  2. Validate repositories, SSH, and the release with ci/publish --dry-run.
  3. Start a detached worker with a durable log under /var/log.
  4. Cleanly stop all agents, preserving resume state and home data.
  5. Force-publish, install on 192.168.1.4, and run post-deploy checks.

If the terminal disconnects, run --monitor with no RUN_ID to follow the latest
publish. Pass --yes to skip the destructive confirmation prompt.
USAGE
}

say() { printf '[factory-publish] %s\n' "$*"; }
die() { printf '[factory-publish] ERROR: %s\n' "$*" >&2; exit 1; }

while [ "$#" -gt 0 ]; do
    case "$1" in
        --yes) YES=1 ;;
        --preflight) MODE="preflight" ;;
        --monitor)
            MODE="monitor"
            if [ "${2:-}" != "" ] && [[ "${2:-}" != --* ]]; then
                RUN_ID="$2"
                shift
            fi
            ;;
        --worker)
            MODE="worker"
            RUN_DIR="${2:-}"
            [ -n "$RUN_DIR" ] || die "--worker requires a run directory"
            shift
            ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

require_host_terminal() {
    if [ -n "${AICLI_SESSION_ID:-}" ] || [ -n "${AICLI_AGENT_ID:-}" ]; then
        die "run this from the Unraid host terminal, not from an AICliAgents session"
    fi
}

require_clean_repo() {
    local repo="$1" label="$2" status
    status="$(git -C "$repo" status --porcelain --untracked-files=all)"
    [ -z "$status" ] || {
        printf '%s\n' "$status" >&2
        die "$label repository has local changes; commit or archive them before publishing"
    }
}

probe_installer_urls() {
    local plg="$PLUGIN_DIR/$PLG" url count=0
    local -a urls=()
    mapfile -t urls < <(sed -n 's|.*<URL>\(.*\)</URL>.*|\1|p' "$plg" | sort -u)
    [ "${#urls[@]}" -gt 0 ] || die "no installer URLs found in $plg"
    say "probing ${#urls[@]} published installer artifacts before shutdown"
    for url in "${urls[@]}"; do
        if ! curl --fail --location --silent --show-error \
            --connect-timeout 5 --max-time 20 --range 0-0 \
            --output /dev/null "$url"; then
            die "installer artifact is unavailable; agents remain running: $url"
        fi
        count=$((count + 1))
    done
    say "installer artifact probes passed ($count/${#urls[@]})"
}

preflight() {
    require_host_terminal
    for command_name in curl git php setsid nohup readlink tail timeout; do
        command -v "$command_name" >/dev/null 2>&1 || die "required command not found: $command_name"
    done
    [ -x "$PUBLISH" ] || die "canonical publisher not found or not executable: $PUBLISH"
    [ -f "$PLUGIN_DIR/$PLG" ] || die "plugin descriptor not found: $PLUGIN_DIR/$PLG"
    [ -d "$INDEX_DIR/.git" ] || die "Factory index checkout not found: $INDEX_DIR"
    [ -x "$STOP_SCRIPT" ] || die "installed clean-stop script not found: $STOP_SCRIPT"
    [ -x "$RESUME_CAPTURE" ] || die "Factory resume pre-capture helper not found or not executable: $RESUME_CAPTURE"
    require_clean_repo "$PLUGIN_DIR" "plugin"
    require_clean_repo "$INDEX_DIR" "Factory index"

    local branch local_head remote_head
    branch="$(git -C "$PLUGIN_DIR" branch --show-current)"
    [ "$branch" = "master" ] || die "plugin checkout must be on master (currently: ${branch:-detached})"
    git -C "$PLUGIN_DIR" fetch --quiet origin master
    local_head="$(git -C "$PLUGIN_DIR" rev-parse HEAD)"
    remote_head="$(git -C "$PLUGIN_DIR" rev-parse origin/master)"
    [ "$local_head" = "$remote_head" ] || die "plugin master is not aligned with origin/master"

    # This wrapper runs on Factory itself. A direct local probe avoids consuming
    # an unauthenticated sshd slot before the release gate starts.
    [ -d /mnt/cache ] || die "Factory host filesystem is unavailable"

    probe_installer_urls
    say "validation-only publish pass (agents remain running)"
    CI_LOCAL_EXEC=1 "$PUBLISH" \
        --plugin="$PLUGIN" \
        --plg="$PLG" \
        --message="$MESSAGE" \
        --server="$SERVER" \
        --key="$SSH_KEY" \
        --force-publish \
        --no-root-sync \
        --dry-run
    say "preflight passed"
}

latest_run_id() {
    [ -f "$RUN_ROOT/latest" ] || die "no previous Factory publish is recorded"
    tr -d '[:space:]' < "$RUN_ROOT/latest"
}

monitor_run() {
    local id dir pid log exit_file rc
    id="$1"
    dir="$RUN_ROOT/$id"
    pid="$(tr -d '[:space:]' < "$dir/worker.pid" 2>/dev/null || true)"
    log="$dir/publish.log"
    exit_file="$dir/exit-code"
    [ -n "$pid" ] || die "worker PID is missing for run $id"
    [ -f "$log" ] || die "publish log is missing for run $id"

    say "run: $id"
    say "worker PID: $pid"
    say "log: $log"
    if [ -f "$exit_file" ]; then
        cat "$log"
    elif kill -0 "$pid" 2>/dev/null; then
        tail --pid="$pid" -n +1 -F "$log" || true
    else
        cat "$log"
    fi

    rc="$(tr -d '[:space:]' < "$exit_file" 2>/dev/null || true)"
    [ -n "$rc" ] || die "worker ended without an exit record; inspect $log"
    if [ "$rc" -eq 0 ]; then
        say "SUCCESS — Factory publish completed"
    else
        say "FAILED (exit $rc) — inspect $log"
    fi
    return "$rc"
}

WORKER_EXIT_FILE=""
WORKER_RC=0

worker_finish() {
        WORKER_RC=$?
        local rc="$WORKER_RC"
        trap - EXIT
        [ -n "$WORKER_EXIT_FILE" ] || exit "$rc"
        printf '%s\n' "$rc" > "$WORKER_EXIT_FILE.tmp.$$"
        mv -f "$WORKER_EXIT_FILE.tmp.$$" "$WORKER_EXIT_FILE"
        if [ "$rc" -eq 0 ]; then
            say "worker complete"
        else
            say "worker failed with exit $rc"
            # #93: ci/publish uses nonzero codes for post-deploy verification
            # failures too. At that point the new plugin can already be healthy
            # and landed on Flash. Never infer storage damage from the aggregate
            # publisher exit code and never run the repair engine automatically;
            # its deep-rescue path may recreate legacy storage and erase agent
            # layers. The canonical publisher performs its own reconciliation.
            say "automatic storage repair is intentionally disabled; inspect the publisher log and live health before any manual repair"
        fi
        exit "$rc"
}

worker() {
    local log="$RUN_DIR/publish.log" expected_commit

    WORKER_EXIT_FILE="$RUN_DIR/exit-code"
    trap worker_finish EXIT

    exec >> "$log" 2>&1
    say "detached worker started at $(date --iso-8601=seconds)"
    expected_commit="$(tr -d '[:space:]' < "$RUN_DIR/source-commit")"
    require_clean_repo "$PLUGIN_DIR" "plugin"
    [ "$(git -C "$PLUGIN_DIR" rev-parse HEAD)" = "$expected_commit" ] \
        || die "plugin HEAD changed after preflight; refusing to stop agents"
    say "source commit: $expected_commit"
    say "pre-capturing every live Codex resume identifier with the release candidate"
    AICLI_FACTORY_SOURCE_HANDLER="$PLUGIN_DIR/src/includes/handlers/TerminalHandler.php" \
        "$RESUME_CAPTURE"
    say "cleanly stopping agents; resume identifiers and dirty home data will be persisted"
    timeout -k 15 240 bash "$STOP_SCRIPT"
    say "clean stop complete; starting canonical Factory publish"

    CI_LOCAL_EXEC=1 "$PUBLISH" \
        --plugin="$PLUGIN" \
        --plg="$PLG" \
        --message="$MESSAGE" \
        --server="$SERVER" \
        --key="$SSH_KEY" \
        --force-publish \
        --no-root-sync
}

if [ "$MODE" = "worker" ]; then
    worker
    exit 0
fi

mkdir -p "$RUN_ROOT"

if [ "$MODE" = "monitor" ]; then
    [ -n "$RUN_ID" ] || RUN_ID="$(latest_run_id)"
    monitor_run "$RUN_ID"
    exit $?
fi

preflight
[ "$MODE" = "preflight" ] && exit 0
require_clean_repo "$PLUGIN_DIR" "plugin"

if [ "$YES" -ne 1 ]; then
    printf '\nThis will cleanly close every AICliAgents session on %s, publish a new\n' "$SERVER"
    printf 'plugin version, install it, then run the canonical post-deploy checks.\n'
    printf 'Type PUBLISH to continue: '
    read -r confirmation
    [ "$confirmation" = "PUBLISH" ] || die "cancelled"
fi

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$"
RUN_DIR="$RUN_ROOT/$RUN_ID"
mkdir -p "$RUN_DIR"
: > "$RUN_DIR/publish.log"
git -C "$PLUGIN_DIR" rev-parse HEAD > "$RUN_DIR/source-commit"
printf '%s\n' "$RUN_ID" > "$RUN_ROOT/latest.tmp.$$"
mv -f "$RUN_ROOT/latest.tmp.$$" "$RUN_ROOT/latest"

nohup setsid "$SELF" --worker "$RUN_DIR" > /dev/null 2>&1 < /dev/null &
worker_pid=$!
printf '%s\n' "$worker_pid" > "$RUN_DIR/worker.pid"

say "detached worker launched; it will survive this terminal disconnecting"
say "reconnect command: $0 --monitor $RUN_ID"
monitor_run "$RUN_ID"
