#!/bin/bash
# capture_resume.sh — shared shutdown-capture bridge (R2/R4).
#
# Provides _capture_resume_before_stop, the bash → PHP bridge that runs
# TerminalService::captureResumeForShutdown(null, <budget>) BEFORE any
# kill/unmount sweep on an OS-lifecycle close path (array stop, server
# shutdown, plugin uninstall/upgrade). The HYBRID entrypoint saves a fast
# disk-fallback resume id for EVERY live session (pass 1, guaranteed) and then
# runs the full clean close within the time budget (pass 2, best-effort,
# no-fallback sessions first). Capture is ADDITIVE — the caller's existing kill
# sweep stays intact AFTER this and is the hard unmount guarantee.
#
# Mirrors the env-var bridge pattern of aicli-supervisor.sh's
# _relaunch_home_sessions / _clear_home_consolidating: the budget travels via an
# env var, NEVER spliced into a `php -r` string. Source-guarded so multiple
# event scripts can source it without redefining.
#
# Safety contract (per spec R2/R7): this must NEVER block a stop. The PHP
# entrypoint is itself budget-bounded and never throws, but we ALSO wrap it in a
# hard `timeout` ceiling (budget + 2s headroom) and `|| true` so even a wedged
# PHP runtime can't hang the shutdown window.

if [ -n "${_AICLI_CAPTURE_RESUME_SOURCED:-}" ]; then
    return 0 2>/dev/null || true
fi
_AICLI_CAPTURE_RESUME_SOURCED=1

# _capture_resume_before_stop <budget_secs>
#   budget_secs — total wall-clock budget for the full-close pass (pass 2).
#                 Defaults to 8s. The disk-fallback pass (pass 1) is fast and
#                 always runs to completion regardless of the budget.
# Best-effort; returns 0 always. Never hangs past budget+2s.
_capture_resume_before_stop() {
    local budget="${1:-8}"
    case "$budget" in ''|*[!0-9]*) budget=8 ;; esac

    # No PHP -> nothing to do (some stop paths can run before/after PHP exists).
    command -v php >/dev/null 2>&1 || return 0

    local emhttp="/usr/local/emhttp/plugins/unraid-aicliagents"
    local manager="$emhttp/src/includes/AICliAgentsManager.php"
    [ -f "$manager" ] || return 0

    local ceiling=$(( budget + 2 ))

    # Hard ceiling via `timeout`, plus `|| true`, so capture can NEVER block the
    # stop even if the PHP runtime wedges. `-k 2`: if SIGTERM at the ceiling is
    # ignored (e.g. PHP stuck in uninterruptible I/O on the home mount being torn
    # down), escalate to SIGKILL 2s later so the event script can't sit blocked
    # past ceiling+2. This `timeout -k` is the SOLE HARD backstop for a hung
    # capture. Handlers are NOT autoloaded by AICliAgentsManager — the entrypoint
    # lazy-requires TerminalHandler itself (the disk-fallback source). The budget
    # travels via env (no interpolation).
    AICLI_CAPTURE_BUDGET="$budget" timeout -k 2 "$ceiling" php -d display_errors=0 -r '
        $_SERVER["DOCUMENT_ROOT"] = "/usr/local/emhttp";
        require_once "/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php";
        if (method_exists("\AICliAgents\Services\TerminalService", "captureResumeForShutdown")) {
            $b = (int)getenv("AICLI_CAPTURE_BUDGET");
            if ($b <= 0) { $b = 8; }
            \AICliAgents\Services\TerminalService::captureResumeForShutdown(null, $b);
        }
    ' >/dev/null 2>&1 || true

    return 0
}
