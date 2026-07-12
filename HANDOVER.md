I'm resuming work on **unraid-plg-aicliagents** (the AI CLI Agents Unraid plugin). Context from previous session (2026-07-12):

**Where this runs:** Claude Code runs directly ON the Unraid test server `.4` (192.168.1.4, hostname `Unraid`) — `/usr/local/emhttp/plugins/…` and `/mnt/user/…` are LOCAL. `.4` is SHARED with other live Claude sessions (e.g. saas-businessOS on runner volume `runner_bos`). Recall memories [[storefront-from-dot4]] and [[session-safe-ui-deploy]] before deploying.

**Repo state:**
- Branch: `master`
- HEAD: `dd58ca58` Feat: … (v2026.07.12.01) — the session-safe Factory publish of the f54f792b feature set. Clean, pushed, level with origin.
- Prior: `f54f792b` the feature work (44 files); `959c1d90` this HANDOVER.md.
- Root workspace repo (`unraid-extensions`): my CI tooling is committed+pushed (`6857272` attestation + GitLab→Forgejo scrub, `f936bb2` visual-review exclusion); a concurrent session's file-editor commits sit on top — leave it alone.

**Running state:**
- Factory (Forgejo) version = **`2026.07.12.01`** — session-safe Factory-published this session (bump + tarball via `publish-factory --no-git`, local git push, then `.4` deploy by direct file overlay + flash/manifest promote — NO `plugin install`, sessions preserved). Forgejo raw `.plg`+`src.tar.gz` serve it, so a `.4` REBOOT now fetches the current features (no longer overlay-only). `.4` flash + `/var/log/plugins` `.plg` = 2026.07.12.01; live plugin code synced to the release (bundle md5 matches `src/assets/ui/index.js`).
- Public Storefront (GitHub `johnpwhite/unraid-plg-aicliagents` main) = `2026.07.11.01` — NOT yet promoted to `2026.07.12.01`. Promote via `jpw-unraid-storefront` when John's ready (net-off the changelog first).

**In flight (Forgejo):**
- #43 Workspace asset tree — IMPLEMENTED + session-safe-deployed (commit f54f792b), NOT released. See its latest comment for the full shipped list. Umbrella issue for the asset-tree + in-page editor + "＋ Add" create flow.
- Un-ticketed features shipped in the same commit (back-fill issues if you want tracking): the workspace-row context menu + inline rename; the managed file-path-convention instruction block (docs/specs/AGENT_FILE_PATH_CONVENTION.md); the vendored CodeMirror editor.

**Recently shipped this session:**
- (commit f54f792b) the whole asset-tree/editor/menu/file-path-block/clickable-root feature set — 44 files.
- Earlier: released 2026.07.11.01 to Factory (Forgejo) + Storefront (GitHub) — #40 clickable terminal paths, #41 direct image paste, #42 pinnable drawer. Plus new CI tooling: session-safe `factory-tested.json` attestation + `ci/attest` + `ci/storefront --force` (root repo).

**Next logical step:**
Pick one (confirm with John): (a) build the per-agent **"Config surface" panel on the Manager page** (reuses `get_agent_config_surface` with no `path` → global scope only — agreed follow-on to #43); (b) **lazy-load the CodeMirror editor bundle** (grew ~330KB→~950KB — dynamic-import `FileEditorModal` so those deps only download on first file open); or (c) **promote 2026.07.12.01 to the public Storefront** (GitHub) once tested — `jpw-unraid-storefront`, net-off the changelog. (Factory publish already done this session.)

**Active constraints / gotchas:**
- **Session-safe deploy only** — overlay files into the live plugin dir; NEVER `ci/run deploy` / `plugin install` (kills agent sessions). Overlay BOTH `index.js` AND `index.css` (css-only changes are invisible if you forget the css — that bit me: the editor rendered unstyled/behind).
- **Never run two `ci/run` for aicliagents concurrently** — corrupts `runner_aicliagents/ui-build/node_modules` (tinypool error, looks like a vitest failure but isn't). Use `run_in_background: true`, never trailing `&`. Fix = `rm -rf /mnt/appdata/runner_aicliagents/ui-build/node_modules` then one clean run.
- **C4 docs are STALE** — big `src/**` + `ui-build/**` changes landed; run `python3 C4-Documentation/tools/c4-drift.py check`, refresh, `… sync`.
- The `2026.07.11.01` attestation/release-gate artifacts don't cover the WIP; a versioned publish re-gates. Live-GUI L4 still auth-blocked (needs the .4 GUI password in `ci/ci.secrets.conf`) — see [[aicliagents-l4-auth-blocker]].
- Pre-deploy green check (container-only, session-safe): `cd .. && ci/run lint unit build --plugin=aicliagents`. Last run GREEN: phpunit 1118, vitest (assetTree 42 / fileEditorApi 11 / fileEditorRouting 6 / menuNav 14), build.

**Skills to load first (only if doing that work):**
- `jpw-unraid-factory` / `jpw-unraid-storefront` — only if cutting a release.
- `jpw-forgejo-backlog` — if back-filling issues.

**Files to read before editing:**
- `docs/specs/WORKSPACE_ASSET_TREE.md` + `docs/specs/AGENT_FILE_PATH_CONVENTION.md` (the two feature specs).
- Backend: `src/includes/services/AssetSurfaceService.php` (descriptors + tree + creatable), `src/includes/handlers/AssetsHandler.php` (get_agent_config_surface, read_file), `src/includes/services/ValidationService.php` (validateIntendedPath), `src/includes/services/hub/FilePathConventionProjector.php` + `HubProjector.php` (policy fence).
- Frontend: `ui-build/src/components/AssetTreePanel.tsx`, `ui-build/src/lib/assetTree.ts`, `ui-build/src/components/fileEditor/` (vendored editor), `ui-build/src/components/AICliAgentsTerminal.tsx` (openFileEditor + openTerminalPath wiring), `ui-build/src/components/DrawerPanel.tsx` + `WorkspaceRowMenu.tsx` (context menu).
