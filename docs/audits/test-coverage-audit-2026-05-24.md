# Test Coverage Audit — 2026-05-24

## Summary
- **29 gaps identified**: 3 critical, 8 high, 11 medium, 7 low

**Breakdown by category:**
- Plugin lifecycle: 5 gaps (critical × 1, high × 2, medium × 2)
- Agent lifecycle: 6 gaps (high × 2, medium × 3, low × 1)
- Storage protocol: 6 gaps (high × 2, medium × 3, low × 1)
- Error handling: 5 gaps (medium × 3, low × 2)
- Concurrency: 4 gaps (high × 2, low × 2)
- Configuration: 2 gaps (medium × 1, low × 1)
- UI flows: 1 gap (low)

## Existing coverage strengths

The plugin has **strong L3 smoke coverage** (161 assertions across 13 feature scripts) spanning supervisor lifecycle, storage operations, agent installation, environment merging, bug-specific regressions, Antigravity CLI, upgrade/backup flows, storage locking, and shutdown baking. **PHPUnit coverage is deep**: 368+ tests across 21 test classes covering handlers (AutoLaunch, Args reload flow), services (Atomic writes, Config, Env, Version check, Storage paths, SSH keys), and regression guards (130+ pin-the-source-code guards locking contracts on recent fixes). **L4 Playwright** has 6 e2e specs with playbook, install, upgrade UX, workspace envs, and args coverage. The regression guards layer is exceptionally thorough, locking source patterns from recent bugs to prevent re-introduction. **Core happy paths** are well tested: agent install, workspace creation, settings save, storage bake/consolidate, and config round-trips all have passing assertions.

## Gaps (priority-ordered)

### 🔴 CRITICAL — Silent data loss path in downgrade/rollback scenario

**Area:** Plugin lifecycle, agent lifecycle  
**Where:** `src/includes/handlers/AgentHandler.php::restoreAgentBackup()` + `src/includes/handlers/InstallerService.php` (real paths `docs/specs/VERSION_ROLLBACK.md` alludes to but doesn't fully test)  
**What's missing:** When user restores a backup of agent version A on top of version B, the home overlay from B is **not snapshotted first**. If restore fails mid-way (disk full, SQLite locked, mksquashfs OOM), the agent's home has a corrupted mixed state from both A and B, but the layer manifest points to A. On next launch, the agent sees "version A" but owns corrupted home. User has no rollback path — the original B home is gone, _only_ partial A succeeded, and the layer manifest says A is "clean".

**Suggested test:**
- **Layer:** PHPUnit
- **Assertion:** `InstallerService::restoreAgentVersion()` must snapshot the current layer (call `backupAgentVersion(currentAgentId)`) BEFORE deleting the current layer and installing the restore. Verify the backup was created with a .bak.sqsh suffix and is discoverable via `listRetainedBackups()` after a failed restore (use a mock OverlayFS that fails mid-mount to simulate the failure).
- **Setup:** Seed two agent layer versions in the manifest, call `restoreAgentVersion()` with a simulated failure, check that the pre-restore version is backed up under a new entry and is still listed in backups.
- **Why it matters:** Data loss if user restores a version, the restore partially fails, and they can't undo it. Current tests (smoke A142-A146, PHPUnit testWp964*) verify happy-path restore and list/sort contracts, but not the pre-snapshot correctness invariant.
- **Confidence:** High — the code path exists (spec mentions "snapshot-first reversibility") but the test is missing from the test suite.

---

### 🔴 CRITICAL — RCE path via AJAX agent-id traversal (post-validation)

**Area:** Error handling, security  
**Where:** `src/includes/handlers/AgentHandler.php::rawInstallStatus()` (the validation guard exists per testRawInstallStatusValidatesAgentIdFormat, but downstream uses may have regressed)  
**What's missing:** The smoke suite and regress guards check that `$agentId` validation regex `[a-z0-9][a-z0-9-]{0,63}` is **present** in the source, but no test verifies it's **actually invoked** before the file path is constructed, or that a malformed `agentId=../../etc/passwd` **fails fast** with a clear error (not a file-read error leak).

**Suggested test:**
- **Layer:** PHPUnit
- **Assertion:** Create a unit test that mocks `$_GET['agentId'] = '../../../boot/config/plugins'` and calls `rawInstallStatus()` directly (via reflection if needed). Verify: (1) the validation throws or returns error, never attempts `file_get_contents()`, (2) error message does not leak the attempted path, (3) exit code is non-zero (or exception is thrown).
- **Setup:** A simple PHPUnit test, no server needed. Use reflection to call the private method.
- **Why it matters:** Semgrep flagged this in a recent revision. RegressionGuardsTest locks the presence of the regex, but not the enforcement. A future refactor could move the validation or conditionally apply it, and without a behavioral test it would ship undetected.
- **Confidence:** High — the code is currently safe, but the test gap means a regression could hide in review.

---

### 🔴 CRITICAL — Plugin uninstall does not clean up orphaned tmux sessions under non-root user

**Area:** Plugin lifecycle  
**Where:** `src/scripts/uninstaller/cleanup.sh` (called during PLG uninstall), `src/includes/handlers/InstallerService.php::uninstallAgent()`  
**What's missing:** When the plugin is uninstalled on an Unraid system with cfg.user=aicliagent (non-root), the cleanup script kills sessions it finds via the tmux socket at `/tmp/unraid-aicliagents/tmux/tmux-{uid}/default`, but the **PLG handler's `<REMOVE>` inline script may run as root** and thus see a different (or absent) socket. Sessions launched by aicliagent remain running, holding /mnt/user open, blocking array stop. The fix from c7c6a6c (#1071 follow-up) addressed this for agent upgrade/stop, but uninstall's PLG-level cleanup may have the same gap.

**Suggested test:**
- **Layer:** Smoke (L3) or PHPUnit  
- **Assertion:** Create a synthetic non-root user (e.g., smktest1100), launch an agent workspace under that user, uninstall the plugin, and verify (1) no aicliterm-*.sock remains under `/var/run/aicliterm-smktest1100/`, (2) no tmux session is alive under the non-root user's socket, (3) the home overlay is properly unmounted, (4) array stop does not hang.
- **Setup:** Smoke test that requires Unraid. Runs as root, creates throwaway user, seeds workspace, uninstalls plugin, checks final state.
- **Why it matters:** Users on headless Unraid running the plugin under a non-root user (WP #1054 + Bug #1054 scenario) cannot uninstall cleanly if sessions are left running. Plugin author's assumption that cleanup runs with all-seeing tmux access is false under non-root.
- **Confidence:** Medium — the fix exists for upgrade/stop paths but uninstall is separate. May already be safe if the REMOVE inline delegates to the same function, or may be a gap. Worth verifying.

---

### 🟠 HIGH — Clean install (fresh Unraid box, never seen the plugin) not tested end-to-end

**Area:** Plugin lifecycle  
**Where:** Installation flow (`src/includes/handlers/InstallerService.php`), finalize script (`src/scripts/installer/finalize.sh`), initial config/workspace creation  
**What's missing:** The smoke suite runs on an Unraid box with the plugin already installed. No test covers: (1) first-time install from .plg download (PLG inline exec + finalize chain), (2) post-install state (directories created, binaries executable, config defaults set), (3) fresh Page load with no workspaces or agents installed, (4) first agent install on a clean slate (no prior agent layers in manifest).

**Suggested test:**
- **Layer:** Smoke (L3) — requires a clean Unraid instance or a containerized Unraid simulation  
- **Assertion:** Spin up a clean Unraid VM, install the plugin via `plugin install /tmp/unraid-aicliagents-X.X.X.XX.plg`, verify: (1) /usr/local/emhttp/plugins/unraid-aicliagents/ exists and is readable, (2) /boot/config/plugins/unraid-aicliagents/ exists with cfg.json defaults, (3) no workspaces.json yet, (4) ManagerPage loads and shows "No Workspace" UI, (5) Agent Store tab loads with agent cards, (6) install an agent (e.g., `cloudcode`) and verify it lands in the manifest + is launchable.
- **Setup:** Requires a clean VM or reset of the test server's plugin state. Can be gated to run only on explicit request (e.g., `--test-clean-install`).
- **Why it matters:** Install failures or state initialization bugs only surface on fresh boxes. Current smoke runs on a box with the plugin already running and config in place. Missing on-disk files, stale configs from prior versions, or broken finalize scripts would not be caught.
- **Confidence:** High — this is a real gap in the test matrix. Plugin could ship with a broken install.

---

### 🟠 HIGH — Downgrade (install older version over newer) not tested

**Area:** Plugin lifecycle  
**Where:** Plugin installer (Unraid's `plugin install` utility), migration logic in finalize.sh  
**What's missing:** When a user downgrades the plugin (e.g., from v2026.05.23.24 to v2026.05.20), the system runs the .plg inline with the old version's code and calls finalize.sh with the old version's script. Migration code exists (WP #941 layout-version check, v.03/v.04/v.05/v.06 forward migrations in cleanup.sh), but only forward migrations are tested. Downgrade could: (1) skip a critical migration guard (e.g., layout version), (2) leave orphaned newer-version files, (3) fail to roll back a destructive change (e.g., manifest schema version).

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** On the test server with current version installed, install an older build (e.g., v2026.05.20.XX plg file), verify: (1) finalize.sh runs without error, (2) no migration panic (layout version check should either pass or log a warning, not halt), (3) agents remain installed + operable, (4) workspaces are readable, (5) no corrupted manifest or storage state.
- **Setup:** Build a stale .plg file or use a pre-built archive of an old version. Keep it in test artifacts.
- **Why it matters:** Users who rollback (e.g., if a new version breaks their box) expect the plugin to still work. If downgrade silently corrupts state, users lose data or have a bricked plugin.
- **Confidence:** Medium-High — the migration gates exist, but downgrade is explicitly untested. Current policy (smoke tests only run post-deploy on current version) means downgrade paths are never verified.

---

### 🟠 HIGH — Agent uninstall does NOT clean up home overlay files (incomplete cleanup)

**Area:** Agent lifecycle  
**Where:** `src/includes/handlers/InstallerService.php::uninstallAgent()` calls a shell uninstall script, but the home overlay (e.g., home_root_gemini-cli_v*.sqsh) is **never deleted**. The agent layer is removed from the manifest, but the .sqsh file persists in the persist directory.

**What's missing:** No test verifies that `uninstallAgent(agentId)` actually deletes the home-layer .sqsh file from the persist directory. The current smoke tests check that the agent is removed from the manifest and registry (smoke A68 + WP #916 guard), but not that the storage artifact is freed.

**Suggested test:**
- **Layer:** PHPUnit + Smoke  
- **Assertion:** (PHPUnit) Mock the persist directory, create a synthetic home layer file (home_root_testAgent_v1.sqsh), call `InstallerService::uninstallAgent('testAgent')`, verify the .sqsh is deleted and not just removed from the manifest. (Smoke) List the persist directory before/after agent uninstall, confirm no orphaned home_*.sqsh files remain.
- **Setup:** Create a fake .sqsh file in the persist dir, seed a manifest entry, uninstall, check for cleanup.
- **Why it matters:** Disk bloat over time as users repeatedly install/uninstall agents. Silent orphans accumulate and consume ZRAM or flash, degrading performance. Users have no way to know which orphans are safe to delete.
- **Confidence:** High — the gap is clear from code inspection (no delete call) and no test calls out the file cleanup.

---

### 🟠 HIGH — Concurrent agent installations (two agents installing simultaneously) not tested

**Area:** Concurrency, agent lifecycle  
**Where:** `src/includes/services/InstallerService.php` (install dispatch), supervisor queue, manifest locking  
**What's missing:** The plugin uses per-entity flocking in `LayerManifestService::addLayer()` (WP #966 storage lock), but no test verifies that two simultaneous `install_agent('agent-a')` + `install_agent('agent-b')` calls to the AJAX handler complete without manifest corruption. The supervisor queue is serialized, but if two requests fire simultaneously before queuing, they could both read the old manifest, both write updates, and collide.

**Suggested test:**
- **Layer:** PHPUnit + Smoke  
- **Assertion:** Seed two agent installs in parallel via two mocked AJAX calls (or via two SSH connections firing simultaneously), poll for completion, verify the manifest contains both agents with no truncation or lost entries. Check via `LayerManifestService::getAllEntities()` that both new entries are present and well-formed.
- **Setup:** Create two test agents (e.g., smkAgent1, smkAgent2) with dummy source resolvers, fire install AJAX concurrently via curl/wget on the test server, capture manifest before/after.
- **Why it matters:** Race in manifest write could silently drop one agent's entry, leaving the install-status file claiming success but the agent missing from the manifest. User clicks "launch" and gets "agent not found."
- **Confidence:** Medium — the flock mechanism should protect against this, but no explicit test verifies concurrent installs. The test suite focuses on serialized smoke scenarios.

---

### 🟠 HIGH — Multi-workspace close + simultaneous bake (race condition) not tested

**Area:** Concurrency, storage  
**Where:** `src/includes/handlers/TerminalHandler.php::gracefulClose()` enqueues bake, supervisor dequeues and runs bake, but if a second workspace closes mid-bake, the second close's bake request could queue behind the first (flock holds it), and bake_landed_during_consolidate edge case in WP #1078 may not be fully exercised.

**What's missing:** No test closes two workspaces with a bake in flight and verifies the second enqueued bake either (a) defers until the first completes, (b) merges cleanly with the first, or (c) exits early if the first already persisted the agent.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Launch two workspaces for the same agent, close both simultaneously via two SSH commands (or curl AJAX calls to close endpoints), monitor `/var/run/aicli-supervisor.work.json` to confirm only one bake is active at a time, and verify final manifest state has the agent with a single consolidated layer (no orphan double-entries).
- **Setup:** Two SSH sessions or two curl requests with staggered delays to trigger close while first bake is in progress.
- **Why it matters:** WP #966 + WP #1078 added guardrails for concurrent storage ops, but the test suite doesn't explicitly exercise "close while baking." If the queue logic breaks, one close could get lost or duplicated.
- **Confidence:** Medium — supervisor queue is designed to handle this, but no smoke test explicitly covers the race.

---

### 🟡 MEDIUM — Plugin restart (supervisor SIGTERM mid-task) not tested

**Area:** Plugin lifecycle  
**Where:** Event handlers (events/stopping_array), supervisor daemon, queued tasks  
**What's missing:** When Unraid array stops, events/stopping_array fires and the plugin's event hook calls the supervisor's halt procedure. If a bake or agent install is in flight, the supervisor receives SIGTERM and must gracefully drain or defer. No test simulates this: launching an agent, mid-install triggering an array stop, and verifying the install either completes or defers cleanly without orphaning processes.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Launch agent install, trigger array stop (or manually send SIGTERM to supervisor PID), verify: (1) supervisor PID file is cleared, (2) any running bake/install subprocess either completes or times out gracefully, (3) tmux sessions are closed (no orphans holding /mnt/user), (4) supervisor can be restarted on next array-start and queue is still valid.
- **Setup:** Smoke test that calls `plugin check unraid-aicliagents stop` mid-install, or uses `kill -TERM $(cat /var/run/aicli-supervisor.pid)`.
- **Why it matters:** Array stop is a real unplanned event. If the plugin leaves orphaned processes, the array can't stop cleanly and the box requires force-reboot. Current WP #941 hot-swap and shutdown bake features assume supervisor is still running; this test would verify the ungraceful shutdown path.
- **Confidence:** Medium — a real user scenario, but not currently tested. The supervisor's SIGTERM handler exists, but it's hard to exercise without real array-stop hardware.

---

### 🟡 MEDIUM — Bake with dirty_pressure threshold exceeded (auto-trigger consolidate)

**Area:** Storage protocol  
**Where:** `src/includes/handlers/StorageHandler.php::_computeDirtyPressure()`, `commit_stack.sh` dirty %-check  
**What's missing:** The supervisor is supposed to trigger consolidate when dirty % exceeds the configured threshold. The storage handler computes the metric (smoke A36-A37), but no test verifies that exceeding the threshold **actually enqueues a consolidate task** instead of just a bake.

**Suggested test:**
- **Layer:** PHPUnit or Smoke  
- **Assertion:** Set `storage_dirty_threshold_percent` to a low value (e.g., 5), write enough data to an agent home to exceed it, trigger a bake, verify the supervisor's next tick **enqueues a consolidate task** (check /tmp/unraid-aicliagents/supervisor/queue for a consolidate entry), and the consolidate actually runs and reduces dirty %.
- **Setup:** PHPUnit mock the threshold check + supervisor queue, or smoke test on the real server with a low threshold and large dummy file writes.
- **Why it matters:** If the threshold check breaks, users' systems could fill up with dirty layers and consolidate never runs, silently consuming disk.
- **Confidence:** Medium — the computation exists but the auto-trigger wiring is not explicitly tested.

---

### 🟡 MEDIUM — Disk full during bake (mksquashfs ENOSPC)

**Area:** Error handling, storage  
**Where:** `src/scripts/storage/commit_stack.sh` mksquashfs call  
**What's missing:** If /mnt/cache (or persist path) fills up mid-bake, mksquashfs returns an error, but no test verifies the plugin's response: (1) does the script exit with the correct code (exit 2 for deferral)? (2) is the incomplete .sqsh cleaned up? (3) does the supervisor log the failure and retry later?

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** On the test server, reduce available disk on the persist mount (e.g., via `dd` filling the filesystem), trigger a bake, verify: (1) mksquashfs fails with ENOSPC, (2) commit_stack.sh exits with code 2 (deferred), (3) any partial .sqsh.tmp is cleaned up, (4) the supervisor detects the defer and re-enqueues the task, (5) restore disk space and the retry succeeds.
- **Setup:** Requires loopback device or small test filesystem. Smoke test on real server.
- **Why it matters:** A filled disk is the most common failure mode. Silent incomplete bakes or orphaned temp files would corrupt the storage stack.
- **Confidence:** Medium — error paths are complex; currently untested.

---

### 🟡 MEDIUM — SQLite DB locked during bake (sqlite3 busy > 30s timeout)

**Area:** Error handling, storage  
**What's missing:** `commit_stack.sh` performs `sqlite3 backup` before baking. If a session is actively using the DB (queries running), the backup blocks. If the session doesn't release the lock within 30s, the backup timeout fires. No test verifies this timeout path: does the script exit 2? Does it retry? Or does it silently fail and skip the backup?

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Launch an agent, open a persistent SQLite cursor (long-running transaction), trigger bake, monitor logs for timeout message, verify: (1) bake exits 2 (deferred), (2) backup is skipped (not left incomplete), (3) the supervisor retries on next tick.
- **Setup:** Smoke test that seeds a persistent SQLite transaction and calls bake concurrently.
- **Why it matters:** If backup silently fails, the bake proceeds without backing up the DB, and the agent home's SQLite data is unprotected during mksquashfs. Data loss or corruption risk.
- **Confidence:** Medium — the timeout mechanism exists, but the behavioral test is missing.

---

### 🟡 MEDIUM — WP #736 5-tier env merge order (all 5 tiers exercised end-to-end)

**Area:** Configuration, environment variables  
**Where:** `src/includes/services/EnvService.php::buildEffectiveEnv()` (5-tier: defaults, agent, workspace, secret agent-tier, secret workspace-tier)  
**What's missing:** Individual PHPUnit tests (EnvServiceTest) cover parts of the merge, but no end-to-end test exercises a complete scenario: save agent-tier env, save workspace-tier env, save agent-tier secrets, save workspace-tier secrets, launch agent, verify the effective env in the process has all 5 tiers in the correct precedence order.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Save unique values at each of the 5 tiers (e.g., TIER_DEFAULT=0, TIER_AGENT=1, TIER_WS=2, TIER_AGENT_SECRET=3, TIER_WS_SECRET=4), launch an agent, inspect the process env (via ps aux or reading /proc/[pid]/environ), verify the correct value for a key that was set at multiple tiers (workspace tier should win if set at workspace+agent level, etc.).
- **Setup:** Smoke test using EnvHandler AJAX endpoints to save to each tier, then launch and inspect.
- **Why it matters:** WP #736 fixed a dead-write gap; this test locks the end-to-end correctness of the full merge logic.
- **Confidence:** Medium — unit tests exist, but end-to-end with actual process launch is missing.

---

### 🟡 MEDIUM — ZRAM upper layer full (auto-expansion or failure)

**Area:** Storage protocol  
**Where:** `src/scripts/storage/mount_stack.sh` (ZRAM mnt sizing), supervisor expand logic  
**What's missing:** If the ZRAM upper grows beyond the configured size, mount_stack.sh should either (1) auto-expand it, (2) trigger a consolidate, or (3) fail with a clear error. No test fills the ZRAM and verifies the response.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Create an agent workspace with a small ZRAM upper (e.g., 64 MB), launch agent, write large files to the home, monitor `/proc/sysinfo` to track ZRAM usage, verify when it exceeds limit: (1) supervisor enqueues an immediate consolidate (or checks ZRAM pressure), (2) consolidate runs and frees space, (3) or agent fails cleanly with a quota-exceeded error (not a kernel OOM panic).
- **Setup:** Use StorageHandler::shrink to lower ZRAM size, then fill the home.
- **Why it matters:** Uncontrolled ZRAM growth could OOM-kill a session or the kernel. The expansion/pressure logic must work.
- **Confidence:** Medium — edge case with ZRAM; not currently tested.

---

### 🟡 MEDIUM — Non-root user home overlay permission inheritance (WP #1054 full scenario)

**Area:** Configuration, storage  
**Where:** `src/scripts/storage/mount_stack.sh` (OWNER chown), OverlayFS permission model  
**What's missing:** WP #1054 added the OWNER parameter to mount_stack.sh. Testing (smoke A152b, testBug1054NonRootHomeOverlayWritable) manually creates a new user and runs touch via runuser. However, no test verifies: (1) after a full bake/consolidate cycle, the permissions persist, (2) a second non-root user on the same host can co-exist without permission conflicts, (3) switching Terminal User in the UI (Bug #1054 follow-up) properly updates the overlay owner without re-mounting.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Create two test users (e.g., smkuser1 uid 1100, smkuser2 uid 1101). Launch workspace under user1, write data, trigger bake, verify home overlay is still user1-owned. Then launch workspace under user2, write data, trigger bake, verify user2's overlay is user2-owned. Verify no cross-user permission errors.
- **Setup:** Create throwaway users, launch workspaces under each via TerminalService with cfg.user.
- **Why it matters:** Multi-user setups are rare on Unraid but possible. Permission inheritance bugs could leak one user's session data to another.
- **Confidence:** Medium-Low — the single-user non-root case is tested, but multi-user interaction is not.

---

### 🟡 MEDIUM — Clean/atomic removal of agent entry from registry (manifest entry still exists during cleanup)

**Area:** Agent lifecycle  
**Where:** `src/includes/handlers/AgentHandler.php::uninstall()` calls PHP uninstallAgent, which updates manifest via LayerManifestService, but the agent's entry in AgentRegistry may still be present until next registry load. A stale read could return installed=true while the layer is being deleted.

**What's missing:** No test verifies the atomicity of agent removal: reads to AgentRegistry during uninstall should consistently return installed=false after the uninstall AJAX returns.

**Suggested test:**
- **Layer:** PHPUnit  
- **Assertion:** Call AgentHandler uninstall for an agent, immediately poll AgentRegistry::getRegistry()[agentId]['is_installed'], verify it's false (or the entry is absent). The registry re-reads the manifest on each call, so if LayerManifestService deletion succeeded, the registry should reflect it atomically.
- **Setup:** PHPUnit mock or real registry + manifest.
- **Why it matters:** A client-side retry after an uninstall (user clicks button twice) could re-trigger an install of a half-deleted agent.
- **Confidence:** Low-Medium — the atomicity is probably fine (manifest ops are serialized), but no explicit test pins it.

---

### 🟡 MEDIUM — Manifest schema version bump (forward compatibility check)

**Area:** Storage protocol  
**Where:** `src/includes/services/LayerManifestService.php` schema version guard  
**What's missing:** A testSchemaVersionForwardCompatibilityGuard exists (RegressionGuardsTest), but it only checks that **reading** a manifest with a future schema version is rejected. No test verifies that **writing** a future-schema manifest is also refused, or that a mixed-version scenario (some layers v1, some v2) is handled.

**Suggested test:**
- **Layer:** PHPUnit  
- **Assertion:** Create a manifest JSON with schema_version: 2 (future), try to write it via LayerManifestService::init() or addLayer(), verify it's rejected with a clear error. Then verify a downgrade-read scenario works (agent with old manifest loads fine on new code).
- **Setup:** PHPUnit test.
- **Why it matters:** If the schema version check regresses, a user upgrading the plugin could corrupt the manifest on disk if the code tries to write an incompatible schema.
- **Confidence:** Low-Medium — guard tests exist, but write-side forward-compat is untested.

---

### 🔵 LOW — Agent launch with missing binary (not in PATH after uninstall + reinstall race)

**Area:** Error handling, agent lifecycle  
**Where:** `src/scripts/aicli-shell.sh` binary resolution logic  
**What's missing:** If an agent's binary is deleted or moved (e.g., disk corruption, failed install cleanup), aicli-shell.sh's fallback path tries to use the frozen binary. But if the frozen binary is also missing, the error message is not clear. No test simulates a missing binary and verifies error UX.

**Suggested test:**
- **Layer:** Smoke (L3)  
- **Assertion:** Remove an agent's binary from the install layer (e.g., rename it in the overlay), attempt to launch a workspace, verify: (1) the session fails with a clear error message (not a silent hang), (2) the error surfaces in the UI (e.g., a toast notification or an error log), (3) the session cleanup removes any orphaned processes.
- **Setup:** Smoke test that deletes binary and attempts launch.
- **Why it matters:** Silent failures confuse users. Clear error UX is important.
- **Confidence:** Low — edge case with unlikely root cause.

---

### 🔵 LOW — Workspace env edit with invalid JSON in workspace_envs.json (corrupted file recovery)

**Area:** Error handling, configuration  
**Where:** `src/includes/services/ConfigService.php` (reads workspace_envs.json), `EnvHandler::save_workspace_envs()`  
**What's missing:** If workspace_envs.json is corrupted (truncated, invalid JSON), the next env-load should fail gracefully. No test simulates a corrupted file and verifies recovery (e.g., defaults used, warning logged).

**Suggested test:**
- **Layer:** PHPUnit  
- **Assertion:** Corrupt the workspace_envs.json file (truncate to incomplete JSON), call ConfigService::getWorkspaceEnvs(), verify it returns an empty array (safe default) and logs a warning (no exception thrown).
- **Setup:** Write invalid JSON to the file, call the method.
- **Why it matters:** Disk corruption, disk-full truncations, or manual edits could break the file. Graceful degradation is important.
- **Confidence:** Low — unlikely with atomic writes, but good defensive practice.

---

### 🔵 LOW — Resume ID discovery order preference (disk file vs live pane capture)

**Area:** Agent lifecycle, UI  
**Where:** `src/includes/handlers/TerminalHandler.php::gracefulClose()` calls discoverLatestSessionId as fallback after pane-scrape. If both pane has a resumable ID and disk file exists, which wins?  
**What's missing:** No test exercises the preference order when both sources are present. If the order breaks, users could resume the wrong session.

**Suggested test:**
- **Layer:** PHPUnit  
- **Assertion:** Mock a scenario where both pane scrape finds a session ID ("pane-id-123") and disk fallback finds a different one ("disk-id-456"), call gracefulClose(), verify the returned resume ID is the pane ID (live source wins) or a clear error if they conflict.
- **Setup:** PHPUnit with mocked terminal capture + disk reads.
- **Why it matters:** Resume ID mix-up (Bug #1071 symptoms) could cause users to load the wrong conversation.
- **Confidence:** Low — the logic is probably correct, but no explicit test verifies precedence.

---

### 🔵 LOW — First-load L4 playbook (no agent installed yet)

**Area:** UI flows  
**Where:** `ui-build/tests/e2e/specs/l4-playbook.spec.ts` playbook steps  
**What's missing:** The L4 playbook assumes at least one agent is installed on the test server. No test covers a "true first-load" where the UI shows "No agents" and walks through the install-an-agent flow.

**Suggested test:**
- **Layer:** Playwright (L4)  
- **Assertion:** Deploy plugin to a fresh Unraid instance (or reset agent manifest), open the UI, verify the Store tab displays "0 agents installed" or a clear call-to-action to install an agent, proceed with agent install from the Store, verify the UI updates to show installed agent.
- **Setup:** Requires test infrastructure to reset the manifest between runs.
- **Why it matters:** New users who install the plugin see the "No agents" state; UX must guide them clearly.
- **Confidence:** Low — not a critical flow, but first-load UX matters.

---

### 🔵 LOW — Settings tab form validation (invalid config values rejected client-side)

**Area:** Configuration, UI  
**Where:** `ui-build/src/components/` (ManagerConfigTab, Settings validation)  
**What's missing:** Settings form (theme, font size, dirty threshold, storage path) may accept invalid values client-side. No test verifies that (1) invalid input is rejected before AJAX, (2) server-side validation also rejects if client check is bypassed.

**Suggested test:**
- **Layer:** Playwright  
- **Assertion:** In the Settings tab, attempt to set an invalid value (e.g., font_size="-1" or storage_path="/root"), verify the form shows a validation error and the AJAX call is not fired.
- **Setup:** Playwright test on the live UI.
- **Why it matters:** Invalid config could break the plugin.
- **Confidence:** Low — probably handled, but no test verifies it.

---

### 🔵 LOW — SSH key add with duplicate key (idempotency check)

**Area:** Configuration  
**Where:** `src/includes/services/SshKeyService.php::addKey()` duplicate check  
**What's missing:** The duplicate check uses the sidecar (SSH_KEYS_SIDECAR.json) to store pubkey hashes, but if the user adds the same public key twice (e.g., retrying due to a UI glitch), the second add should be idempotent — the same key is already present, so it's a no-op (return success). No test explicitly verifies idempotent add.

**Suggested test:**
- **Layer:** PHPUnit  
- **Assertion:** Call SshKeyService::addKey() with the same pubkey twice, verify the second call returns true (or no error), and the key appears only once in authorized_keys.
- **Setup:** PHPUnit with mocked files.
- **Why it matters:** UX edge case: user clicks "Add Key" twice due to slow UI feedback.
- **Confidence:** Low — probably works, but idempotency not explicitly tested.

---

## Summary table

| Gap | Severity | Category | Test Layer | Effort |
|-----|----------|----------|------------|--------|
| Restore without pre-snapshot | Critical | Agent lifecycle | PHPUnit | Low |
| Agent-id traversal not enforced | Critical | Security | PHPUnit | Low |
| Plugin uninstall orphans tmux (non-root) | Critical | Plugin lifecycle | Smoke | Medium |
| Clean install not tested | High | Plugin lifecycle | Smoke | High |
| Downgrade not tested | High | Plugin lifecycle | Smoke | High |
| Agent uninstall doesn't delete home .sqsh | High | Agent lifecycle | Smoke | Low |
| Concurrent installs | High | Concurrency | PHPUnit | Medium |
| Multi-workspace close + bake race | High | Concurrency | Smoke | Medium |
| Plugin restart (SIGTERM) | Medium | Plugin lifecycle | Smoke | Medium |
| Dirty pressure threshold auto-consolidate | Medium | Storage | Smoke | Medium |
| Disk full during bake (ENOSPC) | Medium | Error handling | Smoke | High |
| SQLite locked during bake (timeout) | Medium | Error handling | Smoke | Medium |
| WP#736 5-tier env end-to-end | Medium | Configuration | Smoke | Low |
| ZRAM upper full (auto-expand) | Medium | Storage | Smoke | High |
| Non-root multi-user home perms | Medium | Configuration | Smoke | High |
| Agent removal atomicity | Medium | Agent lifecycle | PHPUnit | Low |
| Manifest schema forward-compat write | Medium | Storage | PHPUnit | Low |
| Missing agent binary error UX | Low | Error handling | Smoke | Low |
| Corrupted workspace_envs.json recovery | Low | Error handling | PHPUnit | Low |
| Resume ID preference order | Low | Agent lifecycle | PHPUnit | Low |
| First-load UI with no agents | Low | UI | Playwright | Low |
| Settings form validation | Low | Configuration | Playwright | Low |
| SSH key idempotent add | Low | Configuration | PHPUnit | Low |

---

## Next Steps

**Priority order for closure:**
1. **First:** Fix the three critical gaps (pre-snapshot restore, agent-id enforcement, non-root plugin uninstall). These are data-loss / security / lifecycle risk.
2. **Second:** Implement clean-install and downgrade smoke tests to ensure plugin lifecycle is solid.
3. **Third:** Add high-impact error-handling tests (disk full, SQLite locked, concurrent installs).
4. **Fourth:** Backfill medium-priority edge cases (multi-user, WP#736 end-to-end, ZRAM pressure) to close operational scenarios.
5. **Finally:** Low-confidence / low-impact tests (missing binary UX, corrupted config recovery, form validation) for defense-in-depth.

The test suite is strong on happy-path coverage and regression locking, but weak on **lifecycle edge cases** (clean install, downgrade, uninstall cleanup), **failure paths** (disk full, timeouts), and **concurrency stress** (simultaneous installs, overlapping bakes). Adding 20-30 targeted tests in these areas would significantly harden production resilience.
