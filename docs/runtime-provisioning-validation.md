# Runtime provisioning failure propagation

This is an unreleased source change, not an installable release. Do not install
the current `.plg` to test this branch: its checksums and `src.tar.gz` still
describe the published release. The owner must regenerate the versioned lifecycle
artifacts, source payload and matching `.plg` SHA256 entries through the private
release process after review. Do not bypass checksum checks to test this change.

## Defect and behavior

In the published installer, backend activation precedes runtime installation,
and `bash /tmp/aicli-runtime.sh` is not checked. A rejected empty tmux archive can
therefore be followed by services, finalization, engine exit 0 and parent auto-launch.
The parent's existing PIPESTATUS check correctly catches a failed engine; the
engine was hiding the runtime failure.

The engine now requires a valid runtime installer and propagates its status before
generation activation or storage/service initialization. Runtime acquisition uses
private staging, bounded retries, explicit transfer status, nonempty and pinned
SHA256 checks. Only verified archives enter the persistent plugin cache; retaining
them allows recovery without a network after reboot. Failed acquisitions leave
existing binaries and links intact. Executable candidates are smoke checked before
rename. Node stages its complete directory before replacing an unusable runtime.

Required executable checks cover node/npm/npx, tmux, git-lfs and both SquashFS
tools. fd/rg remain explicitly optional. Auto-launch checks readiness before HOME
consolidation planning, and manual/headless terminal starts check before storage
initialization. A failed ensure-session no longer proceeds to ttyd success.
Already bound terminal sessions remain reusable.

The patch adds no HOME or Agent Storage migration, format, deletion or broad repair
operation. Runtime recovery remains in the runtime installer; a terminal start
fails visibly rather than initiating a potentially slow installation.

## Automated validation

Run `python3 tests/test_runtime_provisioning.py` with Bash available (`BASH` may
select its executable). Fixtures exercise the actual source helper functions,
tmux block, engine runtime gate and parent `.plg` pipeline without writing Unraid
paths. Cases cover empty successful transfers, failed transfers with matching bytes,
checksum mismatch, poisoned cache recovery/offline reuse, failed executable
publication preserving old bytes, successful publication, runtime failure/missing
installer preventing activation, success ordering, parent failure blocking its
post-engine path, optional versus required failure, and managed Node rediscovery.

Twelve tests pass on the development host. Independent verification separately
exercised the helpers, actual parent pipeline and optional/required failures, and
reviewed launch gate ordering. Bash syntax and PHP parsing passed. These are
source-level checks, not Unraid boot or PHP service integration tests.

## Required release and deployment gates

1. Owner review/port into the private source repository, per CONTRIBUTING.md.
2. Build the source payload and lifecycle scripts using the governed release process;
   regenerate versioned `.plg` URLs/checksums. Verify the built payload contains the
   reviewed changes. Record source commit and artifact SHA256 identities.
3. Run PHP integration tests with the actual service bootstrap and callers; verify
   runtime_unavailable and ensure_failed are surfaced to the UI and auto-launch callers.
4. In a disposable Unraid system, test fresh install, repeated install, update,
   generation reuse, restart and reboot. Inject an empty tmux download, DNS/HTTP
   failure, wrong digest, failed extraction and executable timeout. Confirm nonzero
   plugin result, no backend activation/success/autolaunch, preserved old runtime,
   and actionable install.log acquisition diagnostics. Repeat reboot offline using
   the retained verified cache, and without a usable cache.
5. Trace writes against sentinel HOME/Agent Storage trees and compare contents and
   metadata. Test overlapping installer/runtime invocations and launches. The existing
   installer lock and new runtime lock are not a new runtime-generation lease system;
   no claim of full concurrent lifecycle isolation is made by these tests.
6. Obtain explicit operator authorization before production deployment. Do not use
   broad Repair Plugin or inject faults on a production host.

## Rollback

Retain the prior governed release artifacts and known-good runtime/cache before an
authorized test deployment. On the disposable system, restore the prior release via
its supported release path, verify required tools and generation pointers, and rerun
the readiness checks. Do not restore/rewrite HOME or Agent Storage as part of runtime
rollback. If no validated runtime is available, keep new launches blocked and retain
installation logs. Production rollback must be approved with the deployment plan.
