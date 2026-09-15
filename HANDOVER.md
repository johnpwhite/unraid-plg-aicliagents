I'm resuming work on unraid-plg-aicliagents. Context from the previous session (2026-09-15):

**Repo state:**
- Branch: master, pushed, clean.
- Live on 192.168.1.4: **v2026.09.15.05**, promoted with no session closed
  (generation 2026.09.15.05-6789e930d00e52e0). Release gate green and signed for it.

**Shipped this session (all live):**
1. **Side-by-side agent installs, phase 3 (#216, v.01).** An npm, tarball or GitHub-release
   agent upgrade installs beside the running version; open workspaces keep theirs.
   Spec `docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md`.
2. **Upgrades no longer interrupt (v.02).** No blocked terminals, no "queued", no force
   close for side-by-side; a completion notice says how to move a workspace over.
   Spec `docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md`.
3. **Switch a workspace to the installed version (v.02, fixes v.03).** Workspace menu item;
   names the version (it can be a downgrade); sends NO continue.
   Spec `docs/specs/WORKSPACE_APPLY_AGENT_VERSION.md`.
4. **Close intent (#218, v.02).** Closing no longer flashes an untracked row.
   Spec `docs/specs/WORKSPACE_CLOSE_INTENT.md`.
5. **Supervisor 39 s stall (v.03).** Old-generation reference scan is now one /proc pass.
6. **Voice mail (#210, v.04).** Kept messages, drawer badge and row counts, panel,
   Speak / Voice mail / Off per workspace, retention settings, admin tools + CLI
   (`voicemail`, `voicemail-heard`). Spec `docs/specs/VOICE_MAIL.md`.
7. **Claude resume by id (#221, v.05).** A named Claude session's exit line prints its name;
   `ClaudeSessionNameResolver` maps it to the conversation id from Claude's `custom-title`
   records. Spec `docs/specs/CLAUDE_RESUME_BY_SESSION_ID.md`.

**Open issues, not started:**
- **#222** Switching a workspace's version reloads the whole page and leaves a white line
  across the terminal. Proposed: reconnect the iframe in place; capture state if the line
  persists.
- **#219** Terminal shrinks with dot-filled space after a deploy. `window-size latest` is
  DELIBERATE (phone + desktop). Reproduce in a throwaway tmux session before changing
  anything; the likely fix is a `refresh-client -S` once reattach settles.
- **#214** Workspace launcher exposes injected secrets in process command lines.
- Phase 4 of side-by-side: the three `curl_install` agents. Needs its own design.

**Active gotchas:**
- Ad-hoc storage-op scripts write the REAL layer manifest and can leave halt markers that
  block every Playwright click. Sweep halts, manifest, loops and mounts before a gate.
- The L4 container cannot SSH to the box. To run ONE Playwright spec after
  `../ci/run deploy`: `docker run --rm -v /mnt/appdata/runner_aicliagents:/work -w /work/ui-build
  mcr.microsoft.com/playwright:v1.59.1-jammy npx playwright test tests/e2e/specs/<spec>
  --project=chromium` (bootstrap auth first with `.ci/runners/auth-bootstrap.sh` if
  `tests/e2e/.auth/storageState.json` is missing). A Chromium signal 11 is a container
  flake; rerun.
- Smoke tests that call real services write real stores (voice mail). Clean them in the
  smoke file's own cleanup.
- The FUSE guard refuses a Bash call that mixes `find`/`grep -r` with a `/mnt/user` path;
  keep them in separate calls or use `/mnt/cache`.

**Files to read before editing:** the spec for the area, then
`C4-Documentation/c4-component-*.md`.
