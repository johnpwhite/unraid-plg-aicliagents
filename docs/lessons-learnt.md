# Lessons Learnt — unraid-plg-aicliagents

---

## 2026-06-07 — Graceful close races the relaunch loop for fast-exiting agents → relaunch + hard-stop

**Context:** Closing a workspace whose agent was a *fresh* claude (no conversation) didn't close
cleanly: the log showed `gracefulClose: START` → the agent **relaunched** ~5s later → `tmux session
did not exit within 3s — falling back to hard stop` → killed. The session did end (via the hard
kill) but it relaunched once and never took the clean sentinel path. Surfaced during a non-root
user-switch test, but **not** non-root-specific — it's a timing race any fast-exiting agent hits.

**Root cause:** ordering between `TerminalHandler::gracefulClose` and `aicli-shell.sh`'s relaunch
loop. gracefulClose deliberately keeps the tmux session ALIVE while it scrapes the agent's exit
screen for a resume id (`captureResumeForClose`: Ctrl-C×2 + Ctrl-D×2, then a 3-retry capture-pane,
~4s), and only AFTER that touches `close-<id>.flag` + sends Enter to wake the loop's post-exit
"Press ENTER to reload" `read`. The relaunch loop checks the close flag **once, right after the
agent exits** (then parks on the read). A fresh claude exits *instantly* on Ctrl-C — well before
the ~4s scrape finishes — so the post-exit flag check sees no flag and parks on the read. When
gracefulClose finally touches the flag + sends Enter, waking the read returns to the **top** of the
loop, which **re-execs the agent** — the post-exit flag check is never re-evaluated. gracefulClose's
3s poll then sees the session alive and hard-kills. Slow-exiting sessions (claude with a live
conversation) exit *after* the flag is set, so they break cleanly — which is why root closes usually
logged "sentinel observed" and this didn't.

**Fix:** re-check `close-<id>.flag` at the TOP of the `while true` relaunch loop, *before* the agent
is (re)launched. A flag that lands while the loop is parked on the post-exit read is then caught on
wake and breaks cleanly — preserving the scrape window (the read still holds the session open during
the scrape) while eliminating the spurious relaunch and the 3s hard-stop fallback. Guard:
`testGracefulCloseSentinelCheckedBeforeAgentRelaunch` (asserts the first close-flag check precedes
the `perf_log agent.exec.begin` launch marker).

**Lesson:** "touch the flag, then wake the blocking read" only works if waking the read re-evaluates
the break condition. Here waking returned to the loop top and relaunched first. When a sentinel can
be set *while a worker is parked mid-loop*, check it at the loop top (before the side-effecting work),
not only at the point it's expected to arrive. The clean-vs-hard-stop outcome silently depended on
agent exit speed — a classic timing race hidden by a "usually works" fallback.

---

## 2026-06-07 — Non-root agent users: root-side PHP creates `.aicli` root-owned → Permission denied

**Context:** Switched the plugin's run user from `root` to a normal user (`aicliagent`, uid 1003).
The session launched but the terminal showed:
`/tmp/unraid-aicliagents/work/aicliagent/aicli-run-XXXX.sh: line NNN:
.../home/.aicli/.exported_keys_<hash>: Permission denied`.

**Root cause:** an **ownership split** invisible to root sessions. The home overlay upper *is*
chowned to the session user at mount time (`op_mount --owner`, Bug #1054), so `home/` was
`1003`-owned. But `home/.aicli/` was `0:0` (root). The web-side PHP — `ConfigService::saveWorkspaces`
/ `saveResumeId` / env / autolaunch, all routed through `getUserStatePath()` — runs as **root**
(emhttpd) and creates `.aicli/` (+ `workspaces.json`, `args/`) the moment the browser adds a
session. Whichever side touches `.aicli` first owns it; root usually wins the race. The agent
**run-script runs as the session user** and writes `.aicli/.exported_keys_<hash>` (the 5-tier env
tracker) on every loop — into a root-owned, non-group-writable dir → EACCES. For a `root` run user
this never appears because root owns everything.

**Fix:** `getUserStatePath()` now calls `ensureStateDirOwnedBy($stateDir, $user)` — ensures `.aicli`
exists and is `chown -R` to the session user (recursive, to fix root-created children too). The
decision is a pure predicate `ConfigService::shouldChownStateDir($user, $currentUid, $targetUid)`
(root/empty → never; unresolvable user → never; already-owned → never (idempotent on reads);
owned-by-other or absent → chown). Pure-fn unit `UserStateDirOwnershipTest` (6 cases) covers it —
the real chown needs root+posix+a real user, which only exists at the live/L3.5 layer.

**Lesson:** any path written by BOTH the root web tier AND the per-user agent run-script must be
owner-reconciled to the session user, not just the overlay upper. The mount-time `--owner` chown is
necessary but not sufficient — subsequent root writes re-introduce root-owned paths. Audit every
`getUserStatePath()`-derived writer (and any future `$HOME_DIR/...` root write) for the same trap
when running non-root. Quick tell: `ls -lan .../work/<user>/home` shows `home/` as the user but a
child dir as `0 0`.

---

## 2026-06-06 — ttyd reconnects leak tmux clients unless you attach with `-d`

**Context:** Browsing the aicliagents tab with two workspaces open (claude + gemini), the
terminals were "constantly reconnecting" and the box load climbed to ~5. Closing the browser
did not drop the load. The agents and chat history were never at risk — one tmux session, one
agent process each, both healthy throughout.

**Root cause:** ttyd's web client auto-reconnects on **any** websocket drop — and for a web
terminal those are unavoidable: a hidden/`visibility:hidden` background iframe (the non-active
workspace) gets throttled by the browser, proxies time out idle sockets, networks blip. Each
reconnect re-runs ttyd's command (`runuser → aicli-shell.sh → tmux attach-session`), adding a
**new** client to the session. `aicli-shell.sh` attached **without `-d`**, so the prior client
was never evicted — and because `runuser`/`setsid` puts each attach in its own session, ttyd
can't SIGHUP it on disconnect either (same orphan-detachment class as Bug #1067, but for attach
*clients* not the ttyd process). So "attached" clients accumulate without bound (observed: 7 on
one gemini session, 3 on claude; `tmux list-clients` shows them all live). tmux mirrors the
agent TUI to **every** attached client on every redraw → the load is N× the render work, and the
visible churn is each stale client's pty still being driven.

**Fix:** `aicli-shell.sh` now `exec tmux -u attach-session -d -t "$SESSION"`. `-d` detaches all
*other* clients on attach, so every (re)connect collapses back to exactly one live client —
self-healing across reconnects. The freshly-recreated workspace that "was stable" had exactly
1 client, which is the steady state `-d` restores. Guard:
`testTtydAttachEvictsStaleClientsToPreventReconnectLeak` (RegressionGuardsTest).

**Lesson:** a web terminal **will** reconnect — treat reconnects as routine, not exceptional. Any
`tmux attach` reachable from a ttyd command must use `-d`, or every reconnect is a permanent
leaked client. The diagnostic tell is `tmux list-clients` showing many clients on one session
while only one browser tab is open; the load is the multi-client redraw, not the agent. (The
manual SSH-chip attach in DrawerPanel.tsx is a deliberate single attach, not an auto-reconnect
loop — it intentionally does *not* take `-d`, so a user SSHing in doesn't kick their own browser
session off.)

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
