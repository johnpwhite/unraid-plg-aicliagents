# Audit Synthesis — 2026-05-24

Reviewed both subagent audits (race + security) against the actual code. Verdicts and proposed actions below. The audits OVER-REPORTED severity in several places (typical Haiku pattern); my verifications follow.

---

## Race-condition audit (`race-audit-2026-05-24.md`) — verdicts

| # | Severity claimed | My verdict | Reasoning |
|---|---|---|---|
| 1 | CRITICAL — Loop mount race | **LOW** (downgrade) | Loop mount semantics: kernel pins the inode at `mount -o loop`. If the source file is `unlink()`ed AFTER mount, the inode stays alive and the overlay reads it correctly until the loop is detached. The only "loss" is when detached, and by then the data is in the new consolidated layer. Real issue is "leaked loop devices accumulate" — resource leak, not data loss. Atomic-write tempfiles (`.foo.tmp.PID.epoch`) start with `.` so the glob doesn't match in-progress writes. |
| 2 | HIGH — Marker mtime collision | **FALSE POSITIVE** | Walked through the scenarios: same-nanosecond writes are captured by mksquashfs (which reads the live filesystem AFTER the 50ms sleep, so the write is on disk) AND wiped from upper by selective_upper_cleanup (mtime == marker is in the wipe set), so the new lower has them. Suggested fix (sleep-before-touch) is semantically equivalent. No data loss path. |
| 3 | HIGH — Atomic write collision | **LOW** (downgrade) | Per-entity bake lock (`flock -n 9`) prevents concurrent same-entity bakes. Back-to-back bakes in the same second are rare (mksquashfs takes >1s in practice). The existing post-mv verification (`! -e final OR -e tmp`) correctly detects mv-n failure. Nano-precision filename would be defence-in-depth, not a real-bug fix. |
| 4 | MEDIUM — Manifest replace race | **FALSE POSITIVE** | WP #966 (commit 13efcc6) added the per-entity lock specifically to cover this. The lock IS taken before manifest+delete (line 327-337). Reconcile respects the lock. Haiku missed the WP #966 design rationale: the lock is taken AFTER mksquashfs deliberately so bakes don't block on long consolidates. Smoke A136-A138 already test this. |
| 5 | MEDIUM — Loop mount cleanup on overlay failure | **LOW** (real but narrow) | If `mount -t overlay` fails (ENOMEM, kernel issue), the loop mounts created earlier in the script are left in place. Subsequent retries skip remounting (the `mountpoint -q` check). Worst case: stale loop mounts pointing at since-deleted files. Same "leaked loop devices" outcome as finding 1. Worth a small defensive fix. |

**Net real-action items from race audit:** 1 (LOW: tidy loop mount cleanup on overlay-mount failure). NOT blocking storefront publish.

---

## Security audit (`security-audit-2026-05-24.md`) — verdicts

| # | Severity claimed | My verdict | Reasoning |
|---|---|---|---|
| 1 | HIGH — Unverified TLS / no SHA256 for `runtime.sh` binary downloads | **HIGH — confirmed** | `runtime.sh` downloads node / tmux / fd / ripgrep / squashfs-tools from public CDNs over HTTPS without integrity verification. URLs are pinned to specific versions, but a network-position attacker (DNS hijack, malicious WiFi) could intercept TLS via a misconfigured CA + serve malicious binaries that execute as root. Fix: hardcode SHA256 per binary, verify post-download, fail closed. Industry-standard practice. Worth fixing before storefront. |
| 2 | MEDIUM — Manifest URL not validated for HTTPS | **MEDIUM — confirmed** | `CurlInstallSource::probeManifestVersion` and related calls accept any URL for `source.manifest_url`. Today all registered vendors use HTTPS, but a user-registered vendor with HTTP could silently downgrade. Cheap defence-in-depth: reject non-`https://` URLs at validation time. |
| 3 | MEDIUM — Missing explicit `--cacert` on curl | **LOW** (downgrade) | curl's default IS to verify TLS with the system CA bundle. Explicit `--cacert` pinning is defence-in-depth but introduces maintenance cost (the path is distro-specific, breaks if the CA bundle moves). The default behaviour is already secure; the failure mode requires a misconfigured system. Not worth the maintenance burden for the marginal gain. |

**Net real-action items from security audit:**
1. **HIGH:** Add SHA256 verification to `runtime.sh` binary downloads (file an item, action in a follow-up WP).
2. **MEDIUM:** Add `https://` scheme validation to `CurlInstallSource` / `GithubReleaseSource` / `TarballSource` URL handlers.

Neither is blocking the WP #1078/#1080/#1081 race-fix storefront publish — both pre-date the current changes.

---

## Action plan

### Ship-blocking (must land before storefront)
**None.** v2026.05.23.24 (currently on factory) is safe to publish to storefront. The race fixes work; the audit findings are either false positives, downgraded, or pre-existing issues that don't gate the upgrade safety.

### Short-term follow-up (next 1-2 versions on factory)
1. **WP #1082:** SHA256 verification in `runtime.sh` for node / tmux / fd / ripgrep / squashfs-tools downloads. Compute SHA256 of each currently-pinned URL once; bake into the script; verify after wget; fail closed on mismatch.
2. **WP #1083:** `https://` scheme validation in `CurlInstallSource` / `GithubReleaseSource` / `TarballSource` before any curl/wget invocation.

### Low-priority defensive hardening
3. Loop mount cleanup on overlay-mount failure in `mount_stack.sh`.
4. Nano-precision in atomic_write_layer.sh filenames (`%N` suffix).

### Storefront publish — sign-off

**Recommended:** publish factory v2026.05.23.24 to storefront. Race-fix correctness is verified by smoke + reproduction; audit findings don't gate this release.

---

## Test coverage audit (`test-coverage-audit-2026-05-24.md`) — verdicts

Haiku reported 29 gaps (3 critical, 8 high, 11 medium, 7 low). High false-positive rate: ALL THREE "critical" findings are false positives on verification. The audit also misclassified several "high" items.

| # | Claim | My verdict | Reasoning |
|---|---|---|---|
| C1 | Restore without pre-snapshot (data-loss) | **FALSE POSITIVE** | `InstallerService.php:542` explicitly calls `self::backupAgentVersion($agentId, $persistPath)` before restore. Snapshot is correctly performed. Haiku missed the line. |
| C2 | RCE via agent-id traversal | **FALSE POSITIVE** | Two layers prevent this: (a) `rawInstallStatus()` validates via regex at AgentHandler.php:279 and exits early on mismatch; (b) all other handlers route through `AgentRegistry::getRegistry()[$agentId]` which returns null for any non-registered id, bailing the operation. The registry is a strict whitelist. Defence-in-depth regex in MORE handlers would be tidy but not "critical". |
| C3 | Non-root uninstall orphans tmux | **FALSE POSITIVE** | Both `uninstaller/cleanup.sh:49-54` AND `uninstaller/uninstall-engine.sh:58-63` already iterate per-uid tmux sockets ("Non-root audit: iterate every per-uid tmux socket so non-root sessions get killed too") — commit c7c6a6c shipped this. The smoke test A158 explicitly guards 12 tmux call-sites. |
| H1 | Clean install not tested e2e | **TRUE GAP** | Smoke runs on a pre-installed plugin; no test exercises first-time install on a virgin Unraid. Requires a clean VM or reset utility — not trivial to add. Medium priority. |
| H2 | Downgrade not tested | **TRUE GAP** | No test installs an older version over current. Medium priority. |
| H3 | Agent uninstall doesn't delete home .sqsh | **FALSE POSITIVE** | Audit confused agent layers with home layers. `InstallerService.php:254-256` deletes all `agent_${id}_*.sqsh` files via glob. Home layers (`home_${user}_*.sqsh`) are per-user, not per-agent — correctly NOT deleted on agent uninstall. |
| H4 | Concurrent agent installs | **POSSIBLY TRUE** | Smoke A136-A138 cover the storage lock contract; no smoke explicitly fires two `install_agent` AJAX calls in parallel. Per-entity flock should handle it; explicit test would harden. |
| H5 | Multi-workspace close + bake race | **POSSIBLY TRUE** | WP #966/#1078 guardrails should cover this; no explicit smoke exercises the race. Same shape as H4. |

### Test coverage action items (real gaps only)

1. **Clean-install smoke** (H1) — requires test infrastructure to wipe + reinstall the plugin between runs. Useful but not blocking.
2. **Downgrade smoke** (H2) — install an archived older `.plg` over current; verify state preserved. Useful but not blocking.
3. **Concurrent install + close-while-baking smokes** (H4, H5) — additive smoke assertions; low effort, valuable hardening.
4. **WP #1078 PHPUnit guard for restore-snapshot invariant** — although line 542 does the snapshot, no regression guard locks this. Add one PHPUnit assertion to `RegressionGuardsTest.php` that verifies `restoreAgentVersion` calls `backupAgentVersion` before mutating state.

Most of the audit's medium/low items (29 total) are legitimately gaps but represent diminishing returns. The core hot paths (install, bake, consolidate, mount, env merge, secrets, supervisor queue, lifecycle hooks) ARE well-covered by the existing 161-assertion smoke suite + 368 PHPUnit tests + 130 regression guards.

### Updated storefront publish — sign-off (post-test-audit)

**Still recommended:** publish factory v2026.05.23.24 to storefront. The test-coverage audit confirms the existing test posture is strong on the critical paths; the gaps are in lifecycle edges (clean install, downgrade) and concurrency stress tests that don't gate the WP #1078 fix correctness.
