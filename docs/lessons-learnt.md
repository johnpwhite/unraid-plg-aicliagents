# Lessons Learnt — unraid-plg-aicliagents

---

## 2026-06-03 — Two session-close paths drifted; resume capture must be ONE primitive

**Context:** After upgrading an agent, the workspace relaunched on the new binary but with a
fresh conversation — no `--resume`. Only claude/opencode/agy were even partially covered.

**Root cause:** there were TWO close paths. `TerminalHandler::gracefulClose` (UI close button)
scrapes the agent's exit screen for its resume hint (`--resume <id>` / `--conversation <id>`),
falls back to a disk scan, and calls `ConfigService::saveResumeId`. The pre-upgrade close
`AgentHandler::_closeSessionsForUpgrade` did **none of that** — it sent Ctrl-C and killed the
session. So `AutoLaunchService::launchAllPending` read `getResumeId()` → null → relaunched fresh
(or skipped, depending on `freshIfNoResume`). `aicli-shell.sh`'s own post-exit GUID-sync that
would write the resume file is deliberately skipped on graceful close (the `close-<id>.flag`
`break`s the relaunch loop *before* the sync — the comment literally says "graceful-close is
handled by PHP", but only gracefulClose was doing that handling).

A first patch mirrored a *disk-only* capture into the upgrade path — but that only covers agents
with a disk session store (opencode/agy/claude). Agents that print their resume id **only on the
exit screen** (gemini, copilot, kilocode, codex, factory, nanocoder, goose, qwen, pi) still lost
resume. The exit-screen **scrape** is the agent-agnostic capture; disk discovery is a fallback.

**Fix (#1306):** extracted `TerminalHandler::captureResumeForClose()` — the single quiesce+capture
pipeline (universal exit keys incl. agy's Ctrl-D, the 3-retry exit-screen scrape, the disk
fallback, then save). Both callers use it; teardown stays per-caller (gracefulClose lets the
shell loop break on the sentinel; the upgrade path hard-kills survivors before the binary swap).
The duplication had *also* hidden a second bug — the upgrade path sent Ctrl-C only, never Ctrl-D,
so agy never quiesced on upgrade. Guard: `testUpgradeCloseUsesSharedResumeCapture`.

**Lesson:** when two code paths do "the same operation with different teardown," extract the
shared *operation* and parameterise the teardown. Mirroring is a band-aid that silently drifts —
this exact capture logic diverged twice. NOTE: the regression guards pin a lot of this logic to
`TerminalHandler.php` by token (`--conversation[= ]`, `C-d`, `discoverLatestSessionId`,
`saveResumeId`) — keep the shared primitive IN that file (not moved to a service) or ~10 guards
break. The L2 PHPUnit guards are NOT run by the publish gate (only L1/PHPStan/ESLint + L3 smoke) —
run them manually: `php C:/tmp/phpunit.phar --bootstrap tests/bootstrap.php tests/php/RegressionGuardsTest.php`.

---

## 2026-06-03 — Unraid fires events from the plugin's OWN `event/` dir, NOT `dynamix/events/`

**Context:** Array stop hung repeatedly on `/mnt/user: target is busy` — agent sessions held the share open and our `stopping` handler (which evicts them) appeared never to run. The handler had been in the tree for months; it had simply never fired on any install.

**Root cause:** `/usr/local/sbin/emhttp_event <event>` (the script emhttpd calls) loops over `/usr/local/emhttp/plugins/*/event/<event>` — i.e. **each plugin's own `event/` subdirectory**, as either an executable file or a dir of executables. It does **NOT** read `/usr/local/emhttp/plugins/dynamix/events/<event>/`. Our `finalize.sh` had been writing wrapper hooks into the dynamix path (dead — nothing reads it) and never created an `event/` entry for our own plugin. So zero event handlers ever ran.

**Fix:** `finalize.sh` now `ln -sf "$EMHTTP_DEST/src/event" "$EMHTTP_DEST/event"` and deletes the dead dynamix hooks. Verify after deploy: `ls -la /usr/local/emhttp/plugins/unraid-aicliagents/event` should be a symlink → `src/event`, with executable `stopping` / `stopping_array` / `disks_mounted` inside.

**Also non-obvious — event timing (from the emhttp_event header comments):** `stopping` fires at the *start* of cmdStop, **before** any unmount → the correct hook to evict share-holding sessions. `stopping_array` fires **after** shares are already unmounted → too late to prevent EBUSY. Hook `stopping`, not `stopping_array`, for anything that must release `/mnt/user` before unmount.

---

## 2026-06-03 — Deleting an empty dir from a zram overlay upper breaks overlayfs copy-up (ENOENT)

**Context:** "Save failed" on the Manage-Session overlay; PHP `file_put_contents` to `~/.aicli/tmux/*.json` returned ENOENT even though the home overlay was mounted `rw` and the parent appeared to exist (visible via the squashfs lower).

**Root cause:** `selective_upper_cleanup` (common.sh) swept now-empty dirs out of the zram upper with `find -mindepth 1 -type d -empty -delete`. Once `.aicli/` was emptied + removed from the upper, overlayfs copy-up of that directory **from the read-only squashfs lower** failed with ENOENT on kernel 6.18.33 — so every write under that path failed. Writing directly to the upper layer worked; writing through the merged overlay did not (the merged dentry was wedged). Same class as WP #1224 (deleting `$upper` itself), one level down.

**Fix:** don't delete the empty dirs — strip `trusted.overlay.opaque` + `trusted.overlay.redirect` xattrs so they stay as transparent scaffolding and new writes land in the upper without needing copy-up. **Repair a live wedged overlay** by lazy-umount + remount with the correct newest-first lowerdir order (a bare remount re-mounted with `lowerdir=empty` and hid all history — order matters).

---

## 2026-06-03 — `navigator.clipboard` is undefined over plain HTTP (insecure context)

**Context:** The drawer's SSH/key tab did nothing when clicked on the HTTP WebGUI, and the chip falsely flashed "Copied".

**Root cause:** the async Clipboard API only exists in a **secure context** (HTTPS or localhost). Unraid's WebGUI is plain HTTP by default → `navigator.clipboard` is `undefined`. `navigator.clipboard.writeText(...)` therefore threw a *synchronous* `TypeError` that aborted the click handler before any state update; the trailing `.catch()` only catches promise rejections, not the sync throw.

**Lesson:** in any plugin React/JS that copies to clipboard, optional-chain it (`navigator.clipboard?.writeText(...)`) and gate UI that claims success on `window.isSecureContext && !!navigator.clipboard`. When false, offer a manual select-and-copy affordance instead of asserting a copy happened. Platform-aware copy hint: prefer `navigator.userAgentData?.platform` (returns `"macOS"`) → fall back to `navigator.platform` → `navigator.userAgent`.

---

## 2026-05-28 — Manual deploy workaround when .4 cannot reach factory GitLab

**Context:** The test server at 192.168.1.4 cannot authenticate to the private GitLab at 192.168.1.38 for raw file downloads. `plugin install` gets HTML redirect pages (10580-byte sign-in pages) instead of actual scripts, causing "Modular Engine execution failed".

**Key discovery:** Unraid's `plugin` command (dynamix.plugin.manager) at line 420-421: *"If file already exists, do not overwrite"* — when no `<MD5>` or `<SHA256>` is specified in a PLG `<FILE>` entry, the download is skipped if the file already exists at the destination path. This means you can pre-stage real files at `/tmp/aicli-*.sh` before running `plugin install` and it will use them.

**Deploy recipe for when publish-and-deploy.php is unavailable:**
1. Delete stale HTML files: `ssh root@192.168.1.4 "rm -f /tmp/aicli-*.sh /tmp/aicli-src.tar.gz /tmp/aicli-stop-warning.page"`
2. SCP all installer scripts to their expected paths (see PLG `<FILE Name="/tmp/aicli-...">` entries for the mapping)
3. SCP the PLG: `scp unraid-aicliagents.plg root@192.168.1.4:/tmp/plugins/`
4. Run: `ssh root@192.168.1.4 "plugin install /tmp/plugins/unraid-aicliagents.plg"`

**Also:** `tests/` is not in `src.tar.gz`. For L3 smoke to pass, SCP the tests dir:
```
scp -r tests/ root@192.168.1.4:/usr/local/emhttp/plugins/unraid-aicliagents/tests/
```
Note: run `mkdir -p .../tests/` on the server FIRST, then SCP, to avoid nested `tests/tests/` structure.

---

## 2026-05-28 — `publish-to-github.sh` is a broken stub (missing commit + no URL transforms)

**Problem:** The `.sh` script in `.gemini/skills/unraid-storefront/scripts/` is missing:
1. `git add .` + `git commit -m "Official Release v..."` between `git checkout master -- .` and `git push` on the deploy-github branch
2. URL transformations (GitLab → GitHub raw URLs)
3. PLG entity resolution (DOCTYPE strip, `&pluginURL;` → literal GitHub URL, etc.)

The script reports "SUCCESS" because `git push` silently force-pushes the OLD commit (no-op) and exits 0.

**Workaround:** Run the Python transform script (`~/.claude/jobs/c3511db4/transform-plg.py`) to produce the public PLG, then manually do the git operations on deploy-github:
```bash
git checkout deploy-github
git rm -rf .
git checkout master -- .
# remove exclusions (.gemini/, ui-build/, CLAUDE.md, etc.) + install transformed PLG
python3 /path/to/transform-plg.py unraid-aicliagents.plg CHANGES.public.xml /tmp/public.plg
cp /tmp/public.plg unraid-aicliagents.plg
git add .
git commit -m "Official Release v<VERSION>"
git push public deploy-github:main --force
git checkout master
```
The transform script is saved at `/Users/johnwhite/.claude/jobs/c3511db4/transform-plg.py` but job dirs are ephemeral — copy it somewhere permanent if needed again.

---

## 2026-05-28 — macOS sed quoting mangling in publish-to-github.sh VERSION extraction

The `.sh` publish script uses `sed -n 's/.*"\([^"]*\)".*/\1/p'` on a line like `<!ENTITY version "2026.05.24.03">`. On macOS BSD sed, this produces the correct value. However, the script then does:
```bash
VERSION=$(grep 'ENTITY version' unraid-aicliagents.plg | sed ... | tr -d ' ')
```
When the command substitution is nested, bash on macOS produces `'2026.05.24.03\n' | tr -d '` in the variable, mangling the commit message. This is a bash/sed quoting compatibility issue specific to macOS. The grep+sed pipeline needs to be replaced with a more robust extraction.

---

## 2026-05-28 — CA index does not need updating on each release

The `unraid-community-applications-index/unraid-aicliagents.xml` just contains static metadata including the PLG URL (which points to `main`). It does NOT contain a version number. CA reads the PLG at `pluginURL` to find the current version. As long as the PLG on GitHub `main` has the new version, CA will see it on the next refresh cycle. The `update-index.ps1` script is only needed if the metadata (description, icon, category, etc.) changes — not for routine version bumps.

---

## 2026-05-27 — Antigravity CLI (agy) glog falls back to stderr when log dir absent

When `agy`'s log directory (`$HOME_DIR/.gemini/antigravity-cli/log/`) doesn't exist, Go's `glog` library writes to stderr — the same TTY as the TUI. This corrupts the display with messages like `I0527 22:02:xx.xxxxxx experiment_manager.go:39] ...`. Pre-create the dir in `aicli-shell.sh` per-loop-iteration (same block as `.cache`/`.config`/`.local`). Fixed in WP #1227, v2026.05.24.03.

---

## 2026-05-27 — OverlayFS upper/ deletion: writes silently fail, reads still work

When the `upper/` directory is deleted while an overlayfs mount is still live (kernel holds an orphaned inode), the overlay appears healthy — reads from lower layers still work — but ALL writes fail with ENOENT. This is invisible in `mount`, `df`, `findmnt` output. The only symptom is writes failing.

**Diagnosis:** `touch <merged-path>/.test` returns ENOENT while `ls` on the same path succeeds.

**Recovery:** 
1. Kill sessions using the overlay
2. `umount -l <merged-path>`
3. `mkdir -p <upper-path>/upper`
4. PHP bridge `init <entity> true` to remount cleanly

**Root cause (WP #1224):** `selective_upper_cleanup()` in `common.sh` used `find -type d -empty -delete` without `-mindepth 1`, so it deleted `upper/` itself. Fixed with `find "$upper" -mindepth 1 -type d -empty -delete`.

---

## 2026-05-27 — Factory publish script entity/attribute split-brain (WP #1226)

`publish-factory.php` updates `<PLUGIN version="...">` but NOT `<!ENTITY version "...">` in the PLG DOCTYPE. `consolidate-changelog.ps1` reads the ENTITY to determine what version has shipped; the mismatch means the PLG's src.tar.gz tarball URL in FILE entries still pointed at the old version tarball. Users who upgraded got old code silently. Manual workaround: always bump BOTH the ENTITY declaration (line ~5) AND the PLUGIN attribute (line ~12) before regenerating the tarball. **Fix needed in `publish-factory.php`** (WP #1226).
