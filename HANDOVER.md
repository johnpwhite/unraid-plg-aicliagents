I'm resuming work on unraid-plg-aicliagents. Context from 2026-09-23 AEST (evening):

**Repo state:**
- Branch `master`, HEAD `945b47f2` (v2026.09.23.06 + a test-only commit) plus this handover commit. Working tree clean.
- Workspace root repo `unraid-extensions` HEAD `4693230` (host-identity guard in `ci/`).

**Running state:**
- .4 runs v2026.09.23.06, active generation `2026.09.23.06-72134bb35e6f9f5f` (session-safe promote).
- Public store (GitHub) is still **2026.09.15.08**. All forum reporters run that version.

**Waiting on John:** he is testing on .4 first. On his go: run the Storefront flow (`/jpw-unraid-storefront`) — net-off `<CHANGES>` since 2026.09.15.08 into user-facing notes, show him, then push to public GitHub. Then draft a forum reply (thread page 4: Uliphant, pmyoung, fatredwombat1, protagonista) for him to post.

**Shipped this session (v2026.09.23.04, all Forgejo issues closed with evidence):**
- #297 `/mnt/user` storage target: allowed but advised against (picker + move dialog advice), useCache=only share resolves to pool path, `executeMigrate` re-validates, `evictAll` kills only the plugin's own ttyd/tmux. Docs: `docs/USER_GUIDE.md`, `README.public.md`, `docs/specs/STORAGE_TARGET_PICKER.md`.
- #296 consolidate refresh-only mode (never switches agent generation mid-bake), bound-upper re-checks, refuse a bake that loses the agent binary; `-processors` now goes before the `-e` exclude list (`common.sh`).
- #298/#276 caches excluded from the squashfs instead of deleted from the live upper.
- #292 installer progress: raw `echo >&3` replaced by `log_status` in `runtime.sh`.
- #291 host guard: `ci/lib/host-guard.sh`, exit 37, proven in two gate runs.
- #300 box detector ignores muted placeholder text (relative WCAG contrast < 60% of the box text colour). Real capture fixture `tests/fixtures/pane-input-box/opencode-placeholder.txt`.
- Shift+drag hint moved to bottom-centre (the Activity tray pill covered its close button).
- #279 verified (one EventSource per page), #290 closed (scripts live in `~/.claude/host/`).

**Also shipped (v2026.09.23.05):** #301 iOS image paste via `navigator.clipboard.read()` (unit-tested; John tests on iPhone), #302 e2e operator guard merges instead of overwriting (renames survive a gate), #303 ghost "installed" agent self-heal + no empty-stack remount loop, #304 plain-directory agents stay bound under the `layering` policy (codex/grok/kimi would have mounted empty after a reboot), antigravity-cli removed from the test pipeline (live test archived to `tests/archive/`, e2e never picks it, `ci/scripts/testing/shared/visual_review.sh` now uses `claude -p`). #305 (flaky e2e image-paste timing) is open, low priority.

**Also shipped (v2026.09.23.06):** #306 URGENT for public users on Unraid 7.3.2 — `sqlite_backup_all` passed `.timeout` + `.backup` as ONE argument; sqlite3 3.53 ran only `.timeout`, so no backup was written: every home merge failed closed and every save packed the LIVE database. Fixed (separate args, verified backup, hardlink bake fails closed). `_layer_near_empty` now reads the unsquashfs listing (a small valid layer is 4096 bytes). #236 proven live with an isolated test home and closed. #270 Phase 4 (curl-install agents share `agent-state/<id>/`; unit-tested only, no accounts) closed. #305 flaky e2e fixed (945b47f2).

**Open backlog (no defects):** #287 backup redesign, #299 Relay request grants for external remotes (forum feature request, spec first), #201 deferred.

**Active gotchas:**
- The storage integration layer SKIPS on .4 (live sessions). #296/#298 are proven by unit tests + smoke only, not by a live antigravity-cli reinstall.
- A held Relay notice shows a "1 message waiting" pill bottom-right; it can cover bottom-right UI in L4 tests.
- A workspace's tmux socket is `/tmp/unraid-aicliagents/tmux/s-<id>/tmux-0/default`; use the plugin's own tmux binary (`/usr/local/emhttp/plugins/unraid-aicliagents/.runtime/bin/tmux`), the system tmux says "no server running".
- `ci/run deploy` deletes the runner's `storageState.json`; copy it before a dev deploy if a browser probe needs it.

**Skills to load first:** `jpw-forgejo-backlog`, `jpw-unraid-storefront` (for the release), `jpw-handover` at the end.
