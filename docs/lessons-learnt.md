# Lessons Learnt — unraid-plg-aicliagents

---

## 2026-09-22 — Factory generation and the public storefront are separate release surfaces

**Context:** GitHub issue #12 describes the managed login-shell environment regression. The fix was
already present in the internal plugin history and could be activated safely on Factory, but the
public GitHub repository is a separate standalone release history still at v2026.09.15.08. Its
`main` branch therefore did not contain the fix even after Factory was promoted.

**Rule:** Treat Forgejo `master`/Factory and the public GitHub storefront as separate release
surfaces. Verify the target remote's history and deployed generation before saying a public issue is
resolved. A Factory promotion can prove the fix on `.4`; it does not close the GitHub issue or update
the storefront. Record the public issue as still open until an explicitly authorised public release
sync and issue update have completed.

---

## 2026-09-22 — Provider-owned native wait state must clear on negative detection

**Context:** Claude Code's provider-owned automatic Continue fired and work resumed, but the workspace drawer continued to show `native` until a page refresh.

**Root cause:** `AICliAgentsTerminal` only updated `nativeQuotaStates` when the detector positively matched a wait message. A normal terminal frame therefore left the previous state untouched, and scanning recent scrollback could rediscover an old wait message after the session had resumed.

**Rule:** Provider-owned terminal state is a current observation, not a durable positive-only flag. Read the active terminal viewport, clear the state when no verified current message remains, and never use retained scrollback to reassert a live wait. Keep a regression test for waiting → working without a reload.

---

## 2026-09-22 — The global storage policy must be authoritative over per-entity backend hints

**Context:** The normal persistence default is `/boot/config/plugins/unraid-aicliagents/persistence`, but older entities can retain direct-directory state and report different capabilities. That made Codex queue an upgrade even though the user-facing storage choice was global.

**Rule:** Resolve the effective engine from the global policy plus actual mount/device facts, then migrate all installed entities together. Do not let a stale per-entity backend hint silently override the selected policy; preserve explicit legacy-path handling separately because `persistence_base` is not the active storage root.

---

## 2026-09-21 — A legitimate queued upgrade must use the explicit no-deadline activity state

**Context:** A Codex upgrade correctly waited for its final legacy workspace to close, but its tray activity remained `running`. The ordinary activity watchdog applied its 1,200-second hard cap, marked it `failed`, and left the durable queue request looking terminal even though it was still safe to resume.

**Rule:** A durable state which intentionally has no worker yet must be represented with `ActivityService::wait()`, not a progress update that leaves it `running`. `wait()` is the explicit hard-cap-exempt state and also repairs an activity a previous watchdog pass had already failed; the install worker must re-register itself as running when the queue becomes ready. Guard both the state transition and the behaviour after a backdated hard-cap interval.

---

## 2026-09-21 — A green release gate certifies a committed tree; force the factory publish deliberately

**Context:** The schedule-cancellation fix and its smoke-harness repair were committed before the full release gate. A normal `ci/publish` then saw clean plugin files and classified the run as commit-only, updating only the workspace's gitlink instead of producing a plugin release.

**Rule:** For a clean, already-committed tree that has a fresh signed gate, run `ci/publish --force-publish --gate=.ci-artifacts/release-gate.json` so the factory re-verifies the gate before it bumps the version. Do not omit the explicit `--gate`: a forced publish of an otherwise non-functional tree is gate-exempt by default. If the workspace root has an unrelated dirty submodule, pass `--no-root-sync`, then stage and commit only the released plugin and private-index gitlinks; never let the publish wrapper's root-wide `git add -A` capture someone else's change.

---

## 2026-09-20 — Version selection must never infer or rewrite the release channel

**Context:** Claude Code was deliberately set to Beta, but it later appeared as Stable. When Beta was selected again, the Store picker exposed only the installed version and the latest release.

**Root cause:** The version picker treated a selected version as evidence for which channel should be saved. A browser fixture supplied a Stable radio state while exercising the picker, so that otherwise harmless selection wrote Stable into the real registry. Separately, an L4 playbook restored a hard-coded Stable state rather than the state it found. The old Beta rule admitted only prereleases, but this upstream uses ordinary semantic versions on the Beta/next tag; those releases therefore disappeared from the catalogue.

**Rule:** Only the explicit channel control may call `set_agent_channel`; selecting a version is an install choice, never a channel mutation. Browser tests that touch a live channel must snapshot and restore its actual value, and tests must use an isolated version-cache path. For registries that publish a normal-semver Beta/next tag, make Beta the release train strictly newer than the Stable tag and no newer than the Beta target, with prereleases included too. Keep a rendered-browser regression that fails if version selection tries to call the channel endpoint.

---

## 2026-09-19 — iOS terminal scrolling must own the iframe gesture

**Context:** Chrome on iOS let a one-finger drag move the page toward pull-to-refresh. The ttyd terminal resized during the drag and xterm scrollback did not move.

**Root cause:** CSS on `.xterm-viewport` alone cannot reliably own the gesture. xterm's rendered screen/canvas can sit above that viewport in the hit-test tree, and iOS WebKit does not reliably honour `overscroll-behavior` for pull-to-refresh.

**Rule:** Install the scroll owner inside the same-origin ttyd iframe. Prefer capture-phase pointer events with an iframe-local `touch-action: none` surface on WebKit, retain an explicitly non-passive touch-event fallback for older builds, wait for a small movement threshold, reject horizontal and multi-touch gestures, map a clearly vertical drag to xterm's public `window.term.scrollLines()` API, then call `preventDefault()` and stop propagation. Rediscover `window.term` on the first move and do not require `.xterm-viewport` to exist before using the xterm API; otherwise the page can be successfully locked while scrollback stays still. Keep the passive long-press paste observer in capture phase too, and cancel it when the scroll owner claims the event. Dispose both handlers on iframe reload so reconnects cannot multiply them. Lock the first measured terminal host height on touch devices so browser-toolbar movement cannot resize xterm during a page pull; allow real width/orientation changes to remeasure. The remaining acceptance test must be performed on a real iPhone because desktop Chromium emulation cannot reproduce WebKit pull-to-refresh.

## 2026-09-19 — API voice playback needs a browser recovery path

**Context:** The voice switch unlocked browser audio but its spoken confirmation always constructed a browser `SpeechSynthesisUtterance`, so a configured TTS endpoint was ignored. Automatic event playback and voice-mail replay could also stop silently when an engine clip failed or never began playing.

**Rule:** Keep the voice source text beside every engine audio payload. Try configured TTS first, bound both the server request and client audio startup, and send the same text to browser speech on any failure or timeout. Only an actual `ended` event counts as heard; a fallback or timeout must release the queue without claiming success.

## 2026-07-17 — An agent upgrade is not complete until the new layer is active

**Context:** A forced Claude Code upgrade downloaded and baked the new version, but a surviving process still held the old overlay mount. The backend logged `agent_refresh_still_deferred` and retained the exact-session relaunch manifest, while the progress API nevertheless reported completion. The browser reopened the retired session id, showed “Terminal session not found”, and the retry launched against the stale binary, pinning that layer again.

**Root cause:** “installer process exited successfully” and “upgraded binary is live” were represented as the same terminal state. The 1–99 install marker was the only start barrier, so publishing 100 removed protection even though activation and relaunch were incomplete. The retained manifest documented unfinished work but nothing treated it as authoritative or guaranteed the supervisor would retry it.

**Rule:** Completion belongs to the entire state transition: download → bake → activate new layer → relaunch exact closed set. A durable manifest for an unfinished transition must remain a start barrier, drive an idempotent supervisor job with retry/backoff, and be consumed before 100% is published. The browser must hide retired terminal endpoints while that barrier exists.

**Related boundary:** Force-upgrading an active session deliberately terminates its descendant process tree. Conversation resume does not preserve subagents, monitors, or shell jobs, so the UI must state that consequence explicitly; a queued/non-destructive upgrade should be the normal future path.

---

## 2026-07-16 — `TMUX_TMPDIR` does not isolate a test that inherits `TMUX`

**Context:** A two-session regression test set a private `TMUX_TMPDIR`, created test sessions, and called `tmux kill-server` during cleanup. It was launched from inside a live Codex tmux pane and terminated every live plugin agent session.

**Root cause:** The inherited `TMUX=<socket>,<server-pid>,<pane>` variable takes precedence over socket discovery through `TMUX_TMPDIR`. Every test command remained attached to the production shared server; the apparent private directory provided no isolation.

**Rule:** Any test that starts or stops a real tmux server must use an explicit private socket (`tmux -S <random-private-socket>`) and remove `TMUX`/`TMUX_PANE` from the command environment. Cleanup must name that same explicit socket. A repository-wide shell regression now rejects `tmux kill-server` in tests unless the command includes `-S` or `-L`.

**Related design lesson:** Plugin workspaces share one tmux server, so persisted workspace options must use `set-option -t <session>` and targeted `source-file`; `-g` makes the most recently attached workspace overwrite every other workspace's settings.

---

## 2026-06-09 — Manual storage surgery on .4: the supervisor respawns from the UI poll; read the live persist location, not the stale cfg key

**Context:** Migrating the plugin's per-user home on .4 from `aicliagent` back to `root` (a manual
`rsync --delete` between the two overlay merged mounts). Two things bit during it.

**Gotcha 1 — `aicli-supervisor.sh stop` does NOT hold while the plugin page is open.** Stopped the
supervisor to quiesce storage, confirmed `is_running:false`, then ran a `storagectl consolidate` on
the freshly-rsync'd root home — it deferred with `defer_reason:bake_lock_held`. Cause: the React UI's
`get_supervisor_status` poll calls `SupervisorService::ensureRunning`, which **respawns** the daemon
within seconds of any stop *as long as a browser has the plugin page open*. The respawned supervisor
saw root's dirty upper and started a bake, holding the per-entity bake lock when consolidate tried to
commit. **To quiesce .4's supervisor for manual surgery, close the plugin browser tab first**, THEN
stop it. Silver lining: the deferred consolidate failed *safely* (exit 2, no data loss, wrote-then-
abandoned its layer which was auto-cleaned) — the freshly-hardened busy-arbiter did its job.

**Gotcha 2 — `persistence_base` cfg key is stale; the live store is on the ZFS boot pool.** Initially
mis-read root home's location as the array (`/mnt/user/python`) by trusting the cfg key
`persistence_base="/mnt/user/python"`. That key is **vestigial**. The ACTIVE path is
`home_storage_path="/boot/config/plugins/unraid-aicliagents/persistence"`, and `findmnt --target` on
it returns **`flash/boot  zfs  /boot`** — the Unraid 7.3.1 **ZFS boot pool** ("in lieu of flash").
Both `aicliagent` and `root` homes (layers + `_upper/homes/<user>`) are co-located there. That zfs
(not vfat) fstype is exactly why homes run `upper_mode:disk` and why overlay whiteout char-devices
exist in the upper. **Always resolve the real device with `findmnt --target <home_storage_path>`;
never infer location from `persistence_base`.** ConfigService now keeps that legacy key as a
read-time fallback only, removes it from effective settings, and never lets it override the modern
path. Relevant to the
Epic #1310 `detect_backend.sh` work: a zfs boot pool must classify as **flash** (it's the flash
replacement), not passthrough, so the layering engine keeps running.

**Aside — internal docs leak to the storefront.** `publish-to-github.ps1`'s strip-list omits
`docs/00-governance`, so ADR 0001 shipped publicly with v2026.06.09.01 (harmless, but add it to the
list). Internal implementation plans belong in `docs/specs/` which IS stripped.

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
## 2026-09-19 — xterm scrollback must be changed through xterm, not its DOM viewport

The first iOS drag fix correctly claimed the one-finger gesture and stopped
WebKit pull-to-refresh, but it changed `.xterm-viewport.scrollTop`. That DOM
element is not the source of truth for xterm's line-based scrollback, so the
page stopped moving while the terminal also appeared not to scroll. ttyd
exposes the xterm instance as `window.term`; use its public `scrollLines()` API
for touch-driven movement, carrying fractional row deltas between touch events
and leaving xterm to clamp the top and bottom boundaries. Keep the scrollbar
visible as a secondary control, but do not make it the primary mobile fix.

---
