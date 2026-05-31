I'm resuming work on unraid-plg-aicliagents. Context from previous session (2026-05-31).

## Headline
The quiescent-workspace-lifecycle is **COMPLETE**. The two remaining items —
**#6 force-reclaim on pressure/ceiling + 5-min countdown (WP #1262)** and
**#7 launch⇄mount-op lock (WP #1263)** — are **BUILT, L1/L2-green, and TESTED ON
FACTORY**: published **v2026.05.31.03**, deployed to .4, smoke-verified, and
live-verified end-to-end on .4. Storefront NOT pushed (awaiting explicit go).

## What SHIPPED in v2026.05.31.03

### #6 — Force-reclaim escalation (WP #1262)
The reclaim/consolidate-at-idle path only runs when a home is idle, so a
permanently-connected session would defer reclaim forever. #6 is the bounded
escape valve. **Key realisation: it collapses to "make a stuck-busy home reach
idle"** — reclaim itself is emergent (once force-close drives the home idle, the
already-deferred `_check_consolidate_policy` consolidate runs).
- **`aicli-supervisor.sh _check_force_reclaim_escalation`** — state machine: when
  a home is BUSY (`home_mount_in_use`) AND storagectl's `consolidate.recommended`
  is true (layers≥ceiling-2 / space pressure), arm a countdown
  (`/tmp/unraid-aicliagents/supervisor/escalation/home_<user>.json`, default 300s
  via `AICLI_FORCE_RECLAIM_COUNTDOWN_SEC`), notify once; at the deadline call the
  force-close bridge; stand down (delete state) if the home goes idle / pressure
  relieves first. Wired into `_work_tick` (Step 3c). Boundary helpers are
  injectable (`_now_epoch`, `_manifest_home_ids`, `_home_reclaim_recommended`,
  `_force_close_home_sessions`) so it's unit-testable.
- **Headless force-close** — `TerminalService::forceCloseHome($user)` enumerates
  `listActiveSessionsForHome($user)` and calls the PROVEN `gracefulClose` per
  session (flush→scrape resume-id→saveResumeId→teardown), so a forced close is
  byte-identical to a user click and resume is preserved. New per-session `.user`
  metadata file + pure `filterSessionsByHome` (the TDD seam). Bash bridge
  `_force_close_home_sessions` invokes it via PHP CLI (works headless).
- **UI** — read-only `get_force_reclaim_state` endpoint (StorageHandler) + a React
  countdown banner in `AICliAgentsTerminal.tsx` (polls 5s, 1s tick, M:SS, reason
  copy, "Close now"→`closeTab`); `state==closing` → existing empty state.
- **SUPERVISOR SOURCE GUARD** added: the bottom dispatch is now
  `if [ "${BASH_SOURCE[0]}" = "${0}" ]; then … fi` so the supervisor can be SOURCED
  for unit tests without starting the daemon. Production always execs it → unchanged.

### #7 — launch⇄mount-op lock (WP #1263)
The race: a launch's mount racing a reclaim remount while the home looks idle (the
ttyd doesn't exist yet during `ensureHomeMounted`). **EVERY overlay (re)mount
routes through `op_mount`** (launch, post-bake reclaim refresh, post-consolidate
remount), so the fix is a single flock at that chokepoint:
- **`common.sh mount_op_lock_path <type> <id>`** → `/var/run/aicli-mount-op-<type>-<id>.lock`.
- **`op_mount`** takes `flock -w 30` on it around the umount+overlay-mount. It is a
  SEPARATE lock from the bake lock (which guards the whole multi-minute squashfs
  write — must NOT block a launch) and from PHP's home_mount lock (locking the same
  file there would deadlock the op_mount exec). `-w 30` bounds the wait so a wedged
  holder degrades to "proceed", never deadlock.

## Tests (all green)
- **Bash units (new, run anywhere incl. .4):** `tests/unit/escalation_test.sh`
  (19/19), `tests/unit/mount_op_lock_test.sh` (path 4/4; flock mutual-exclusion
  runs on .4 — skips on git-bash which lacks `grep -P`/`flock`).
- **L2 PHPUnit:** 397 tests, 1206 assertions, 0 failures (104 env-skips).
  `TerminalSessionFilterTest` 7/7; RegressionGuards 147/147 incl. 4 new guards
  (source-guard, escalation wiring, bash↔PHP force-close contract, op_mount lock).
- **Factory:** v2026.05.31.03 deployed to .4, smoke passed (L4 skipped — missing
  `storageState.json`). Two .4 scripts proved it live (FAILS=0):
  `C:/tmp/verify_wp1262_1263.sh` (deployed functions + flock mutual-exclusion +
  forceCloseHome present) and `C:/tmp/live_escalation_check.sh` (real deployed
  `_check_force_reclaim_escalation`: arm→hold→fire→stand-down).

## NEXT STEPS
1. **Push to storefront** when you give the go (`/jpw-unraid-storefront`) — promotes
   v2026.05.31.03 to Tower's update channel. Both fixes are live-verified on .4.
2. Optional: capture `tests/e2e/.auth/storageState.json` once to re-enable L4 and
   get a visual review of the countdown banner.
3. Optional hardening: a durable L3.5 integration case for the full force-close
   (real ttyd session → deadline → close → idle → consolidate). The state machine
   is already unit + live tested; this would add a real-session end-to-end gate.

## GOTCHAS
- Don't publish/redeploy while John's .4 session is live without his OK.
- `grep -P`/`flock` are absent on Windows git-bash → the new bash units use `sed`
  for the deadline read and skip the flock test off-.4. Keep new unit-tested bash
  logic PCRE-free.
- The escalation state filename uses `tr '/ ' '__'` on the user; the PHP endpoint
  matches with `str_replace(['/',' '],'_',$user)`. The mount-op lock uses a broader
  `[^a-zA-Z0-9_-]` sanitisation (internal to bash; no PHP parity needed).
- Ephemeral in C:/tmp: phpunit.phar (PHPUnit 10.5 — `php C:/tmp/phpunit.phar -c
  phpunit.xml`), verify_wp1262_1263.sh, live_escalation_check.sh.

## FILES TOUCHED
- `src/scripts/supervisor/aicli-supervisor.sh` (source-guard, escalation state
  machine + helpers, _work_tick wiring)
- `src/scripts/storage/common.sh` (mount_op_lock_path)
- `src/scripts/storage/storage_ops.sh` (op_mount flock)
- `src/includes/services/TerminalService.php` (forceCloseHome,
  listActiveSessionsForHome, filterSessionsByHome, .user persistence)
- `src/includes/services/UtilityService.php` (getUserIdPath)
- `src/includes/services/ProcessManager.php` (.user cleanup x2)
- `src/includes/handlers/StorageHandler.php` (get_force_reclaim_state)
- `ui-build/src/components/AICliAgentsTerminal.tsx` (countdown banner)
- `tests/unit/escalation_test.sh` (new), `tests/unit/mount_op_lock_test.sh` (new),
  `tests/php/TerminalSessionFilterTest.php` (new), `tests/php/RegressionGuardsTest.php` (4 guards)
- `docs/specs/QUIESCENT_WORKSPACE_LIFECYCLE.md` (status + requirements)

## OpenProject (project 21)
- #1260 Bug (ENOENT race) — Closed; #1261 Bug (antigravity resume) — Closed (both v2026.05.31.02)
- #1262 Feature (force-reclaim) — Developed; #1263 Feature (mount-op lock) — Developed (both v2026.05.31.03)

## SKILLS TO LOAD FIRST
`/jpw-unraid-testing` (L1–L3.5), `/jpw-unraid-storefront` (promote), `/jpw-openproject` (WPs).
