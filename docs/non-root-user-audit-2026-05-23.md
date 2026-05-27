# Non-root Terminal User — code audit

**Audit date:** 2026-05-23
**Plugin version reviewed:** v2026.05.23.16
**Trigger:** session report of Resume button missing for `claude-code` under `aicliagent` (Bug #1071 follow-up); user asked for a sweep of all other paths where non-root may misbehave.

This audit identifies every code path that interacts with **tmux**, **user-owned files**, or **per-user state**, and flags where it makes a root-only assumption.

The plugin runs PHP as **root** (Unraid web server context). Agent sessions run as the configured `cfg.user` (default `root`, but admin can set any system user). Most failure modes are PHP issuing shell commands that target the calling user's resources (tmux server, ssh keys, processes) instead of the agent user's.

---

## The pattern (one root cause, many manifestations)

PHP shells out a `tmux <subcommand>` with no `-S <socket-path>`, which makes tmux default to `$TMUX_TMPDIR/tmux-$(id -u)/default` — i.e. the **calling process's** tmux server. PHP runs as uid 0, so it talks to `/tmp/unraid-aicliagents/tmux/tmux-0/default`. Non-root agent sessions live in `/tmp/unraid-aicliagents/tmux/tmux-<other-uid>/default` and are invisible from the root tmux client.

**Fixed in v.16 for `gracefulClose` only.** Every other call-site below still has this bug.

---

## Findings — by severity

### 🔴 HIGH — feature breaks for non-root configured user

| # | File:line | Symptom | Fix |
|---|---|---|---|
| 1 | `src/includes/handlers/TerminalHandler.php:432, 448, 471` (`agentSignalReload`) | "Apply now" button after changing CLI args / env vars finds no session, returns error. Agent never reloads with the new args under non-root. | Same multi-user iterator as `gracefulClose`. Thread `$tmuxBin = "tmux -S <sock>"` through send-keys calls. |
| 2 | `src/includes/handlers/AgentHandler.php:190-200, 219-236` (`_closeSessionsForUpgrade`) | Agent upgrade dialog enumerates sessions but can't graceful-close non-root ones → no resume id captured → user loses session context across upgrade; tmux session orphans persist. | Same multi-user iterator. Each session entry already carries the agent id; need to also know the user — easiest: probe every per-uid tmux socket like gracefulClose now does. |
| 3 | `src/includes/services/ProcessManager.php:81` (`stopTerminal` with `$killTmux=true`) | Hard-stop endpoint kills ttyd + sock but the tmux session under the non-root user stays alive. Future re-launch may collide on session name. | Use multi-user lookup before `tmux kill-session`. |
| 4 | `src/includes/services/InstallerService.php:238` | Mid-install `tmux kill-session` only hits root's sessions. Non-root sessions for the agent being upgraded survive and reference a stale binary path post-upgrade. | Multi-user iterator. |

### 🟠 MEDIUM — system-level cleanup misses non-root sessions

These run at boot, shutdown, or installer events. Effect is leaked tmux sessions, not user-facing errors — but they accumulate.

| # | File:line | Context | Risk |
|---|---|---|---|
| 5 | `src/event/disks_mounted:26` | Array start cleanup of stale `aicli-agent-*` sessions. | aicliagent sessions from a prior boot survive the array restart. |
| 6 | `src/event/stopping:210, 216` | Full system shutdown — graceful tmux teardown. | Non-root agents miss SIGTERM-window flush; data in upper layer may be lost if user-home commit doesn't drain. |
| 7 | `src/event/stopping_array:147, 153` | Array stop. Same as #6 for array-stop-only flow. | Same. |
| 8 | `src/scripts/user/stop-plugin.sh:119` | Manual `plugin stop` invocation. | Non-root sessions survive. |
| 9 | `src/scripts/user/repair-plugin.sh:40` | "Repair plugin" admin action. | Repair leaves non-root sessions running, defeating the purpose. |
| 10 | `src/scripts/installer/cleanup.sh:154-167, 284` | Pre-install cleanup of stale sessions. | Stale aicliagent sessions persist across plugin upgrade. |
| 11 | `src/scripts/uninstaller/uninstall-engine.sh:58` and `uninstaller/cleanup.sh:49` | Plugin uninstall. | aicliagent's tmux sessions survive `plugin remove` — orphan ttyds and tmux servers stay alive until reboot. |
| 12 | `src/includes/services/InitService.php:172` | First-boot init kill (after a crash recovery). | Usually a clean state anyway, but the asymmetry is wrong. |

**Common fix shape for #5–12 (shell scripts):**
```bash
for sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do
    [ -S "$sock" ] || continue
    tmux -S "$sock" ls -F '#S' 2>/dev/null | grep -E '^aicli-agent-' | while read -r sess; do
        tmux -S "$sock" kill-session -t "$sess" >/dev/null 2>&1
    done
done
```

### 🟡 LOW / defensive — already handled but worth noting

| # | File:line | Note |
|---|---|---|
| 13 | `src/scripts/aicli-shell.sh:25-26` | `chmod 0755 "$TMP_DIR"` if mkdir creates the dir. Runs as the agent user → can't chmod a root-owned dir. **Already handled** by `TerminalService::startTerminal`'s sticky-1777 chmod (Bug #1054 fix), which fires before aicli-shell.sh runs. Worth a comment cross-reference. |
| 14 | `src/includes/services/PermissionService.php:26` | `chown -R nobody:users` — used to make trees readable by the webGUI. Not a non-root-user concern but ensure it's never applied to a path the agent user owns (would break their writes). |
| 15 | `src/includes/services/TerminalService.php:231-233` | `chown sock nobody:users` — correct for nginx access to ttyd's UNIX sock. Not a per-user issue. |
| 16 | `src/includes/services/EnvService.php` | Reads/writes under `getUserStatePath()` → already per-user via cfg.user. ✓ |
| 17 | `src/includes/services/ArgsService.php` | Same as EnvService — `getUserStatePath()` per-user. ✓ |
| 18 | `src/includes/services/AutoLaunchService.php:33` | Delegates to `TerminalService::startTerminal` — inherits the user-awareness already fixed in v.08. ✓ |
| 19 | `src/includes/services/StorageMountService.php` | All home overlays carry the OWNER chown after Bug #1054 v.06–v.08 fix. ✓ |
| 20 | `src/includes/services/SshKeyService.php:119, 138-141` | Per-user `authorized_keys` path explicit. **Caveat:** Unraid strips `command="..."`, `no-pty`, etc. options from **root's** `authorized_keys` (workspace memory `unraid-strips-authorized-keys-options.md`). For non-root, the JSON sidecar's `rebuildOnBoot` flag claims to handle it — needs end-to-end verification on a fresh boot under `aicliagent`. |

### ⚪ NOT A NON-ROOT ISSUE (but seen during the sweep)

- `events/disks_mounted:35` — `pgrep -f 'ttyd.*'` then `kill -9` matched PIDs. `kill` from root works across all uids; `pgrep -f` sees all PIDs root has visibility into (all of them). OK.
- `events/stopping:225` — `pgrep -f 'aicli-run-|aicli-shell'`. Same.
- `ProcessManager::evictAll` (line 92), `evictTargeted`, `isRunning`, `stopTerminal`'s kill steps — all use `pgrep -f` / `pkill -f` against process trees, not tmux server boundaries. Works across users from root.
- `src/scripts/installer/cleanup.sh:154-167` — does have `env -u TMUX_TMPDIR tmux ...` which intentionally targets the system-default `/tmp/tmux-<uid>` to find pre-private-socket leftovers from Bug #1043. That's the right pattern for that specific pre-upgrade hunt, BUT the assertion still applies to root's uid only. For non-root pre-upgrade state, this misses.

---

## Other non-root touch-points worth verifying

These weren't obvious code bugs but need a smoke-test pass before storefront:

- **Workspaces.json reconciliation across user-switch.** When admin switches `cfg.user`, the new user's `workspaces.json` may be empty / stale relative to what the running ttyd processes show. Reload-on-user-switch (v.09) helps for the page state; runtime reconciliation already exists via Bug #1067 orphan sweep.
- **Auto-launch under non-root after boot.** `events/disks_mounted` triggers `AutoLaunchService::launchAllPending` which calls `TerminalService::startTerminal` (user-aware). Untested under aicliagent — should work in principle.
- **Boot-mount sweep (Bug #1065) under non-root.** Calls `ensureAgentMounted` which doesn't pass an OWNER (agent overlays are root-owned by design). Non-root agents need read-execute traversal, which 0755 root-owned grants. Should work, but verify on a fresh boot.
- **claude-code's `--dangerously-skip-permissions` arg under non-root.** Already saved per-user under args. Untested for an agent that ALSO needs special user-level perm grants.
- **Secret Service daemon** (Bug #1042) under non-root — `secret-service-up.sh` is invoked per-user from `aicli-shell.sh`. Should work since aicli-shell already runs as the user.

---

## Recommended fix grouping

**Single follow-up WP — "Bug #10XX: All tmux ls/kill-session sites must iterate per-uid sockets for non-root sessions"**

Scope: 12 distinct call-sites listed in the HIGH + MEDIUM tables above. Common helper extracted to **one place**:

- PHP side: add `TerminalService::resolveTmuxSocketForSession($sessionId): ?string` (or a free helper) that returns the resolved per-uid socket path, then update each call-site to use `tmux -S <sock>` (or pass-through to root's default tmux when no match).
- Shell side (events, installer, uninstaller scripts): drop in a 4-line `for sock in /tmp/unraid-aicliagents/tmux/tmux-*/default; do tmux -S "$sock" ...; done` wrapper.

Tests: smoke A158 — create a fake tmux session under a non-root smoke user, run a tear-down path (e.g. stop-plugin.sh), assert it's gone.

**Optional companion WPs:**
- "Non-root authorized_keys rebuild on boot" — end-to-end verify the SshKeyService non-root path works (rebuild from sidecar). Defer until a forum user reports SSH-attach broken.
- "Bug #1054 / #1071 release-notes consolidation" — fold all the v.06–v.16 non-root work into the storefront changelog (pending your `yes` on the v.11 draft).

---

## Net summary

- **1 multi-user tmux pattern** is the source of all current non-root code-path bugs.
- **4 HIGH-severity** call-sites break user-facing features (apply-now-args, agent upgrade close, hard stop, install kill).
- **8 MEDIUM-severity** call-sites leak orphans on shutdown / install / uninstall.
- **8+ LOW or already-correct** paths reviewed and noted.
- **No issues found** in EnvService, ArgsService, AutoLaunchService, StorageMountService, or the home-overlay / per-user-path resolution (all post Bug #1054 v.06–v.08 fixes).

Recommend filing the single follow-up WP and addressing in one batch.
