I am resuming work on `unraid-plg-aicliagents`. Context from the previous session on 2026-07-19 follows.

## Repository state

- Branch: `master`
- Last product commit before this handover: `5a80edeb release: publish 2026.07.19 stable channel fix`
- All product commits were pushed to `origin/master`.
- The release is `2026.07.19.01`.

## Running state on Unraid `.4`

- The live plugin generation is `2026.07.19.01-ed2b48393473839f`.
- `src.tar.gz` SHA-256 is `ed2b48393473839fb369839ef51d670373897a2c7cde8888170d180ec8a9c2e3`.
- The repository, `/boot/config/plugins/unraid-aicliagents.plg`, and `/var/log/plugins/unraid-aicliagents.plg` all have SHA-256 `2c80cc706c11bd3575297f811bc32553647410016de7b04c83617c6a32b8da5f`.
- The official same-layout plugin installer installed the release, so it is persistent across reboot.
- Exact before/after snapshots of 14 Claude, Codex, ttyd, and tmux process records were identical. The user agents were not restarted by this deployment. Only the plugin supervisor was deliberately replaced.
- Claude's persisted update policy now says `stable`. The installed Claude binary remains version `2.1.212`; deployment did not force an agent upgrade.

## Critical host incident

- Do **not** run the full `ci/run release-gate` on live Unraid `.4`.
- The host rebooted at boot time `2026-07-19 20:27:41` while that gate was exercising storage-functional tests, roughly five minutes after the safe in-place plugin install had completed.
- Tower's remote syslog, `/mnt/cache/appdata/syslog-ng/logs/remote/Unraid/Unraid-20260719.log` on `192.168.1.202`, ends abruptly after rapid loop-device mount/unmount activity, repeated `drop_caches: 3`, and OverlayFS warnings that an upper/work directory was already in use and behaviour was undefined. There is no clean shutdown, kernel panic, OOM, UPS, or thermal-trip record.
- The storage gate is the strongest immediate correlation with this reboot, although the log does not prove the final low-level reset mechanism.
- Plugin Forgejo issue #83 tracks isolating these destructive storage checks from production and is urgent/in progress.

## Storage and thermal evidence

- The post-reboot automatic Btrfs scrub of `/mnt/cache` corrected 117 checksum errors and reported zero uncorrectable errors.
- Btrfs device statistics show corruption counters on both mirrored NVMe devices: 48 on `nvme0` and 72 on `nvme2`; there are no Btrfs read/write/flush/generation errors.
- Both WDC NVMe drives were 47–50 C when checked, with zero critical warnings and zero SMART media/data-integrity errors. They record 9/18 minutes above warning temperature, one drive records 2 minutes above critical temperature, and each records about 278 unsafe shutdowns.
- HDDs `/dev/sdb` and `/dev/sde` were 59–60 C. SATA SSDs `/dev/sdc` and `/dev/sdd` were 56–59 C and SMART attribute 190 was `FAILING_NOW` against a 45 C threshold. `/dev/sdf` was 51 C and records a past threshold failure.
- CPU package temperature was 71 C. A motherboard sensor reported 83 C.
- Heat is a credible contributor to throttling, resets, and storage instability and must be corrected. It does not by itself prove the immediate reboot cause. Corruption on both sides of the Btrfs mirror also makes RAM, CPU, board, or power instability plausible.
- Recommended sequence: stop production storage stress; clean and improve airflow and verify fan curves; return CPU/RAM/XMP/overclock settings to BIOS defaults; run a multi-pass offline memory test; then run a second Btrfs scrub and compare error counters.
- Homelab Forgejo issue #29 tracks the unclean reboot investigation. Homelab issue #30 tracks the urgent thermal remediation.

## Recently shipped

- `b8a13bfb fix: make stable agent channel authoritative`
  - Stable is now saved as `stable`, legacy `latest` is normalized to stable, stable resolves only stable-tagged versions unless the registry has no stable tag, beta resolves beta/next, and exact pins remain exact.
  - Installer, emergency installer, source selection, and UI all use the same saved policy.
  - `tests/php/StableChannelResolutionTest.php` provides the regression coverage.
- `8f41a4bc docs: sync stable channel architecture provenance`
- `0c1ec9df fix: satisfy folder error static analysis`
- `99e07e48 test: use stable parallel Vitest worker threads`
  - The process pool reproducibly failed with `ERR_IPC_CHANNEL_CLOSED`; the thread pool completes all 313 tests in parallel.
- `5a80edeb release: publish 2026.07.19 stable channel fix`
  - Published release assets, scoped a Semgrep false-positive suppression, and moved the supervisor readiness probe into `src/scripts/installer/supervisor-ready.php`.

## Verification completed

- PHP: 1,184 tests, 4,876 assertions, 3 skipped — passed before release packaging.
- JavaScript: 313 tests — passed with the thread pool.
- PHPStan, lint, build, Semgrep, XML/JSON validation, and factory publisher validation passed.
- Focused post-change PHP regression run: 10 tests, 24 assertions — passed.
- Do not repeat the complete host-level release gate until issue #83 is fixed; its storage checks are unsafe on this production host.

## Open work

- Plugin #83: isolate/disable destructive release-gate storage tests on a live Unraid host.
- Plugin #79: ensure every RAM-only CI/deployment path either persists the plugin to flash or explicitly forbids pretending it is a durable install.
- Plugin #78: continue the Claude `2.1.212` segmentation-fault investigation; prior evidence included an attempted 107 TiB allocation before SIGSEGV.
- Homelab #29: determine the low-level cause of the unclean `.4` reboots.
- Homelab #30: remediate excessive disk and system temperatures.
- Fixed and closed this session: plugin #80, #81, #82, #84, and #85.

## Next logical step

Do not stress the live host further. Fix cooling and isolate the release-gate storage tests first, then perform offline memory diagnostics and a comparison Btrfs scrub. Resume the remaining Claude crash investigation only on a thermally and electrically stable host so hardware instability does not contaminate the evidence.

## Important gotchas

- Work through `/mnt/cache/DevelopmentProjects/unraid-extensions/unraid-plg-aicliagents`, not `/mnt/user`, to bypass shfs for this cache-only checkout.
- Live working trees and agent sessions are shared mutable state. Check the branch immediately before committing and never use a deployment path that replaces tmux/ttyd/agent processes.
- The official plugin same-layout installer has now been demonstrated to preserve running agents; retain exact PID/start-time snapshot checks around future live deployments.
- Release artifacts archived outside the repository are under `/mnt/cache/DevelopmentProjects/.agent-archives/unraid-plg-aicliagents-20260719-stable-fix/`.
- Broad C4 drift may remain outside the documents specifically refreshed for this fix; check the drift manifest before unrelated architecture edits.

## Skills and files to load first

- Skills: `jpw-forgejo-backlog`, then `jpw-handover` at the end of the next substantial session.
- Read `./HANDOVER.md`, `src/AgentRegistry.php`, `src/VersionCheckService.php`, `src/InstallerService.php`, `src/NpmSource.php`, `ManagerStoreTab.php`, `ManagerStoreScripts.php`, `tests/php/StableChannelResolutionTest.php`, `tests/php/RegressionGuardsTest.php`, `ci/run`, and the storage functional tests before editing related behaviour.
