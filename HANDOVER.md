I'm resuming work on unraid-plg-aicliagents. Context from previous session (2026-06-02 → 03).

This was a bug-fix + ship session: six user-reported real-use defects found, fixed,
shipped to factory + deployed to .4 + promoted to public storefront. Nothing half-built.

**Repo state:**
- Branch: master
- HEAD: v2026.06.03.02 release-prep commit (close-path refactor #1306)
- Uncommitted: HANDOVER.md + docs/lessons-learnt.md (this handover commit)

**Running state:**
- Factory (GitLab origin): v2026.06.03.02
- Test server .4 (192.168.1.4): v2026.06.03.02 installed + verified; event/ symlink confirmed
- Storefront (GitHub public/main): **v2026.06.02.10** — BEHIND factory. The upgrade-resume
  fix (v.03.01) + close-path refactor (v.03.02) are NOT yet on storefront. Promote with
  /jpw-unraid-storefront when ready (net-off: one "agents resume your conversation after an
  upgrade, for every agent type" line covers both).
- Tower (production): pulls from CA channel on its own schedule. NOTE: Tower's claude-code
  may STILL have ~232 MB stranded in zram with no Flash layer from the failed concurrent-
  upgrade (#1304) — once Tower is on v.10+, re-run the claude-code upgrade OR click the
  Consolidate button (Resources tab) to flush it. Read-only on Tower; do not mutate.

**Recently shipped this session (factory + .4; storefront only up to v.10):**
- #1306 Feature — Unified session-close resume capture (v2026.06.03.02). Extracted
  TerminalHandler::captureResumeForClose() — ONE quiesce+capture pipeline (exit keys incl.
  agy Ctrl-D, 3-retry exit-screen scrape, disk fallback, saveResumeId). Both gracefulClose
  (UI close) and AgentHandler::_closeSessionsForUpgrade call it. Fixes upgrade-resume for ALL
  agent types (was disk-only → only opencode/agy/claude; now the scrape covers gemini/copilot/
  kilo/codex/etc.) and makes agy actually quiesce on upgrade. New guard
  testUpgradeCloseUsesSharedResumeCapture. L2 181/181 (815 assns) green locally; smoke PASS.
- Upgrade-resume initial fix — Bug: post-upgrade relaunch started a fresh chat. Root cause:
  _closeSessionsForUpgrade never saved a resume id (unlike gracefulClose), so AutoLaunchService::
  launchAllPending got null from getResumeId and launched fresh. First patched by mirroring the
  disk capture (v2026.06.03.01), then superseded by the #1306 refactor (v.03.02). Lives in
  docs/lessons-learnt.md (the two-close-paths gotcha).
- #1301–#1305 Bugs (v2026.06.02.x — shipped to storefront at v.10): array-stop event hooks
  (#1301), overlayfs save ENOENT (#1302), agent is_installed regex (#1303), concurrent-upgrade
  consolidate-defer fatal (#1304), drawer SSH tab on HTTP (#1305). See git log / lessons-learnt.

**Earlier this session (all on factory + .4 + storefront v.10):**
- #1301 Bug — Array stop hangs: plugin event hooks never registered (missing event/ symlink).
  THE headline fix. emhttp_event reads each plugin's OWN event/ dir, not dynamix/events/.
  (v.07: finalize.sh creates event -> src/event symlink; secret-service cd /; broadened
  stopping/stopping_array final sweeps to agy/node/claude/gemini/secret-service-daemon)
- #1302 Bug — tmux/workspace "Save failed": overlayfs ENOENT after empty-dir reclaim.
  (v.02: selective_upper_cleanup strips overlay.opaque/redirect xattrs instead of deleting
  empty dirs — deleting broke copy-up from the squashfs lower)
- #1303 Bug — Agents "not installed" after reboot: is_installed regex missed seq-keyed
  layer names. (v.02: $kindAlt extended for delta_\d+_\d{8}T / consolidated_\d+_\d{8}T)
- #1304 Bug — Concurrent agent upgrades fail: pre-install consolidation defer treated as
  fatal, left new agent in zram only. (v.04: commitChanges distinguishes exit 2=deferred
  from 1=failed, falls back to delta bake; v.06: manual Consolidate button on Resources)
- #1305 Bug — Drawer SSH/key tab dead over HTTP: navigator.clipboard undefined threw.
  (v.08 guard + v.09 HTTP-aware manual-copy chip + v.10 platform-aware Cmd/Ctrl hint)
- Also: Consolidate Layer Ceiling label top-aligned (cosmetic, v.05).

**In flight (OpenProject project 21):**
- Nothing in flight — #1301–#1305 (bugs) + #1306 (refactor) all Developed (shipped + verified).
- Pre-existing open backlog untouched: #344, #705, #921, #934, #936, #941, #963, #964, #965,
  #1254, #1259, #1262, #1263 (features), #1276 (bug, shipped earlier).

**Next logical step:** promote factory v2026.06.03.02 → storefront (one net-off line: upgrade-
resume now works for every agent type). Then optionally verify on Tower once it's on v.10+.

**Next logical step:**
None forced. If Tower is now on v.10, verify its claude-code zram data got flushed to Flash
(Consolidate button or re-upgrade). Otherwise pick from the open feature backlog.

**Active constraints / gotchas the next session should know:**
- Unraid events fire from `/usr/local/emhttp/plugins/<plugin>/event/<name>`, NOT
  `dynamix/events/`. Our event/ is a symlink -> src/event (created by finalize.sh). Hook
  `stopping` (fires before unmount), not `stopping_array` (fires after). See lessons-learnt.
- Deleting an empty dir from a zram overlay upper wedges overlayfs copy-up from the squashfs
  lower (ENOENT). Strip opaque/redirect xattrs instead. See lessons-learnt + common.sh.
- `navigator.clipboard` is undefined over plain HTTP — optional-chain all clipboard calls
  and gate "copied" UI on window.isSecureContext.
- The publish wrapper auto-routes test/docs-only changes as "commit-only" (no version bump,
  no deploy, no smoke). Functional src/ + ui-build/ changes get the full publish+deploy+smoke.
- ui-build/node_modules is frequently absent and the L1.6 ESLint gate is non-skippable —
  run `npm --prefix ui-build install` before publishing or the pre-publish regress fails.
- Publish wrapper: bash "C:/tmp/publish_aicliagents2.sh" (runs publish-and-deploy.php for
  --plugin=aicliagents -> factory + deploy .4 + smoke). Storefront: powershell publish-to-github.ps1.
- Smoke [12] now checks the event/ symlink at the live install path, not dynamix/events.
- Occasional smoke flake: SupervisorService::getTickAge() can read >10s on a busy .4 (assert
  [48]) — re-run the publish; not a real failure.

**Skills to load first:**
- /jpw-unraid-storefront (promote to public), /jpw-unraid-testing (L1-L3.5 smoke),
  /jpw-openproject (project 21), /frontend-design (for any drawer/UI work).

**Files to read before editing:**
- src/scripts/installer/finalize.sh (event hook registration — the #1301 fix)
- src/event/stopping + src/event/stopping_array (array-stop session eviction)
- src/scripts/storage/common.sh (selective_upper_cleanup — #1302 xattr fix, ~line 635)
- src/includes/services/AgentRegistry.php ($kindAlt regex — #1303)
- src/includes/services/StorageMountService.php (commitChanges agent defer/fallback — #1304)
- src/includes/services/InstallerService.php (exit-2 non-fatal handling — #1304)
- ui-build/src/components/DrawerPanel.tsx (HTTP-aware SSH chip — #1305)
- docs/lessons-learnt.md (the three new 2026-06-03 entries)
