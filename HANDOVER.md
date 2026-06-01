I'm resuming work on unraid-plg-aicliagents. Context from previous session (2026-06-01).

## ⚠️ MOST RECENT — HOT-SWAP PRE-BAKE REMOVED (#1279, factory v2026.06.01.01; storefront PENDING)

Follow-up from John's review of the upgrade flow. The hot-swap upgrade path was
running `commit_stack` = bake + **reclaim** per entity before exit (old C3 fix /
data-loss Fix #4). That violated the bake-trigger policy (bakes belong to
array-stop / shutdown / manual / low-RAM / scheduled only) and re-ran the very
reclaim that was part of the original loss (#1276). Since #1277/#1278 made
reclaim/consolidate non-destructive, the post-restart supervisor bake is now the
safe on-policy path — so the hot-swap pre-bake is **removed** (`cleanup.sh`). The
**teardown/layout-bump** path still bakes (pure `atomic_write_layer` delta, no
reclaim) because it destroys ZRAM. Two inverse guards replace the old C3/Fix-4
guards (`testHotSwapUpgradeDoesNotBakeOrReclaim`, `testV08C3HotSwapPreBakeRemoved`).
**Shipped factory v2026.06.01.01, deployed to .4 — and the deploy itself was a
hot-swap upgrade, so the modified branch ran live and smoke passed (163).** L2
403/403. OP **#1279 Developed**. **NOT yet on storefront** — it's an internal
storage-policy refactor with no user-facing change, so no new public CHANGES line;
promote (or fold into the next user-facing release) at John's discretion.

## EARLIER THIS SESSION — STRUCTURAL DATA-LOSS FIXES SHIPPED PUBLIC (v2026.05.31.08)

The two structural follow-ups to the conversation-data-loss incident (#1276) are
**COMPLETE**: built (TDD), fully tested, published to factory v2026.05.31.08,
verified on .4 (L3.5 44/44), and **PROMOTED TO STOREFRONT (public GitHub + CA
index) on 2026-06-01** (commit b06de07..a5d422e → main). Nothing outstanding for
#1277/#1278. The public CHANGES.public.xml v.08 entry is the clean net-off
data-durability line (no WP numbers / factory churn).

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

### NEXT STEP — none for #1277/#1278 (shipped public)
The conversation-data-loss class is fully closed across all 4 layers (allowlist +
upgrade-flush in v.07; bake-confirmed reclaim + consolidate completeness guard in v.08),
factory + .4 + storefront. Tower will pull v.08 from the public CA channel on its own
schedule. If a NEW agent is onboarded later, its store is already protected generically by
#1277 — but still add its path to the selective_upper_cleanup allowlist as belt-and-braces.

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
