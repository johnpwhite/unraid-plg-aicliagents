I'm resuming work on unraid-plg-aicliagents. Context from previous session (2026-06-01).

## ⚠️ MOST RECENT — STRUCTURAL DATA-LOSS FIXES SHIPPED TO FACTORY (storefront promote PENDING John's go)

The two structural follow-ups to the conversation-data-loss incident (#1276) are
**BUILT, fully tested, published to factory v2026.05.31.08, and verified on .4** —
but NOT yet promoted to storefront (public). Awaiting John's go for the public push.

### What shipped (factory v2026.05.31.08 → deployed + verified on 192.168.1.4)

- **WP #1277 (Fix #2) — bake-confirmed reclaim. THE generic fix.** `selective_upper_cleanup`
  now wipes a file from the zram upper ONLY if it is PROVEN captured in the just-baked
  layer. `atomic_write_layer` emits the captured-file list (from its verify RO-mount
  `find`) to `AICLI_BAKE_MANIFEST_OUT`; `op_bake` maps those relpaths onto `UPPER_DIR` and
  passes them as a new 3rd arg to `selective_upper_cleanup`, which `comm -12`-intersects the
  wipe set with it. A closed conversation file the bake failed to capture is never reclaimed
  → loss closed generically (any agent, any window), not via the per-agent allowlist (which
  stays as belt-and-braces). Manifest passed UNCONDITIONALLY — empty/missing ⇒ wipe NOTHING
  (safe), never a fall-back to the aggressive legacy wipe.
  Files: `common.sh` (selective_upper_cleanup 3rd arg + comm -12), `atomic_write_layer.sh`
  (AICLI_BAKE_MANIFEST_OUT emission), `storage_ops.sh` op_bake (manifest threading).

- **WP #1278 (Fix #3) — consolidate lowerdir-completeness guard.** After the WP#1246
  pre-consolidate mount refresh, `op_consolidate` asserts the live overlay's lowerdir count
  == on-disk layer count; on mismatch (≥1 layer) it DEFERS (exit 2 — preserves upper AND
  every delta; no marker/lock taken yet) with defer reason `consolidate_lowerdir_incomplete`.
  So a stale/short lowerdir can't bake a lossy consolidated then delete un-captured deltas
  (the May-29 Tower vector). Helpers in `common.sh`: `_overlay_lowerdir_string` (reads
  /proc/mounts), `_count_lowerdirs_in_opts` (ERE, no PCRE), `_mounted_lower_count`.
  Guard wired in `storage_ops.sh` op_consolidate right after the #1246 refresh.

- **Canonical spec:** `docs/specs/CONVERSATION_RECLAIM_DATALOSS.md` (documents all 4 layers:
  #1 allowlist + #4 upgrade-flush shipped v.07; #2 + #3 this change). Set as `canonical_spec`
  on OP #1277 + #1278 (both now **Developed**).

### Tests — ALL GREEN
- **Bash units (root-free, ran on git-bash):** `tests/unit/bake_confirmed_reclaim_test.sh`
  (7 — captured wiped / uncaptured survives / no-manifest=legacy / empty=wipe-nothing),
  `tests/unit/consolidate_completeness_test.sh` (6 — lowerdir-count parsing),
  `tests/unit/reclaim_protect_test.sh` (10 — still green, no regression).
- **L2 PHPUnit:** 403/403 (0 failures, 104 env-skips). 2 new RegressionGuards:
  `testReclaimConfinedToBakedManifest`, `testConsolidateLowerdirCompletenessGuard` (the
  latter also pins guard ordering: after the #1246 refresh, before the old-layer delete).
- **L3.5 integration on .4 (REAL kernel overlayfs/squashfs): 44/44 green, Ring-3 intact.**
  4 new cases in `tests/integration/cases/reclaim_completeness.sh`: R01 (real bake manifest +
  uncaptured file survives reclaim — the loss repro), R02 (full production bake no-loss), C01
  (live short-overlay detection), C02 (complete 3-layer consolidate proceeds, all data
  survives). The 40 pre-existing cases (M01 stale-mount consolidate, H02/H04 bake/consolidate,
  P01–P05 concurrency, F10/F11 busy paths) all still pass → op_bake/op_consolidate edits are
  behaviour-preserving.
- **L3 smoke (publish gate on .4): 163 assertions, SMOKE PASS.** L4 skipped (storageState.json
  missing — known, unrelated).

### GOTCHA — the deployed package does NOT include tests/
`plugin install` ships `src/`, `bin/`, `includes/`, etc. but NOT `tests/`. To run L3.5 on .4
you must sync the harness first:
  `scp -r tests root@192.168.1.4:/usr/local/emhttp/plugins/unraid-aicliagents/`
then `ssh root@192.168.1.4 "bash /usr/local/emhttp/plugins/unraid-aicliagents/tests/integration/run.sh [--filter X]"`.
The storage CODE under test IS deployed (src/scripts/storage/), so the harness measures the
live v.08 scripts. (Wrapper used this session: `C:/tmp/sync_run_itest.sh`.)

### NEXT STEP — storefront promote (PENDING John's OK)
Run `/jpw-unraid-storefront` to promote v2026.05.31.08 to Tower's public update channel once
John confirms. This is the ONLY remaining step; everything is factory-verified. CHANGES.public
should net-on the #1277/#1278 lines (genuine data-loss hardening — public-worthy). NOTE: a
storefront publish fails if the Bash shell cwd is inside ui-build (Windows handle lock) — cd
out first (memory: storefront-publish-uibuild-cwd-lock).

### Other gotchas
- Local ESLint gate (regress.sh L1.6) is "not skippable" and needs `ui-build/node_modules`,
  which is currently ABSENT → the publish wrapper's pre-publish regress fails on it. Worked
  around with `--skip-regress` (publish-factory.php auto-installs node_modules + its ESLint is
  non-blocking; L2/PHPStan already verified locally). For a clean full-gate run, `npm --prefix
  ui-build install` first.
- `tower` SSH alias works for READ-ONLY production diagnosis; all mutating ops → .4.
- Ephemeral wrappers in C:/tmp: publish_dataloss.sh, sync_run_itest.sh, run_bash_units.sh,
  run_phpunit_full.sh, run_guards.sh, op_status.sh. phpunit.phar at C:/tmp/phpunit.phar.

## SKILLS TO LOAD FIRST
`/jpw-unraid-storefront` (promote), `/jpw-unraid-testing` (L1–L3.5), `/jpw-openproject` (WPs).

## FILES TOUCHED
- `src/scripts/storage/common.sh` (selective_upper_cleanup 3rd-arg intersection;
  _overlay_lowerdir_string / _count_lowerdirs_in_opts / _mounted_lower_count)
- `src/scripts/storage/atomic_write_layer.sh` (AICLI_BAKE_MANIFEST_OUT emission)
- `src/scripts/storage/storage_ops.sh` (op_bake manifest threading; op_consolidate #1278 guard)
- `tests/unit/bake_confirmed_reclaim_test.sh` (new), `tests/unit/consolidate_completeness_test.sh` (new)
- `tests/integration/cases/reclaim_completeness.sh` (new — R01/R02/C01/C02)
- `tests/php/RegressionGuardsTest.php` (2 new guards)
- `docs/specs/CONVERSATION_RECLAIM_DATALOSS.md` (new canonical spec)

## OpenProject (project 21)
- #1276 Bug (root cause) — Developed (shipped v2026.05.31.07)
- #1277 Feature (bake-confirmed reclaim) — **Developed** (v2026.05.31.08, canonical_spec set)
- #1278 Feature (consolidate completeness guard) — **Developed** (v2026.05.31.08, canonical_spec set)
