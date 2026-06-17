I'm resuming work on unraid-plg-aicliagents. Context from previous session (2026-06-10).

GOAL (this session): full architecture review of the Epic #1310 storage facade work
("architectural consolidation, optimisations, clearer component design"). **REVIEW COMPLETE,
NOTHING SHIPPED** — no code changes, no publishes. The deliverable is the findings report:

**→ `docs/audits/arch-review-epic-1310-2026-06-10.md`** ← READ THIS FIRST. It is the full
artefact: 10 ranked confirmed findings (F1-F10), 7 verified low-severity items (L1-L7),
2 refuted candidates (recorded so they don't get re-flagged), per-finding fix shapes, and a
suggested OpenProject WP mapping. Findings are **NOT yet triaged with John or filed in OP**.

## Review method (for confidence calibration)
/code-review high effort over `git diff 3f8ddf2...HEAD` (the whole epic, v2026.06.09.02→.21):
7 parallel finder angles → ~35 candidates → deduped to 15 → 1 independent verifier each
(recall-biased). 13 survived (12 CONFIRMED with file:line evidence, 1 mixed), 2 REFUTED.
Every confirmed finding was verified against the actual code, not just the diff.

## Headline findings (severity order — details + fix shapes in the audit doc)
- **F1** Intent-log prune test is a substring match that also matches the `"keep"` field →
  a LOST consolidated layer (sole data holder) classifies HEALTHY, masking total loss.
  3 parser copies: BootIntegrityService.php:272, aicli-supervisor.sh:664, common.sh:848.
- **F2** `_passthrough_guard mount` runs BEFORE op_mount and never consults manifest or
  classifier → manifest-expects-layers + disk-empty on a passthrough box bind-mounts an
  EMPTY dir exit 0, bypassing the total_loss strict-halt. storagectl.sh:314/_pt_mount.
- **F3** Every successful ≤1-layer path migration false-halts path_drift within ~7s
  (executeMigrate never re-points manifest current_persistence_path; marker cleared
  synchronously; the halt then blocks the queued consolidate that would fix it).
- **F4** Migration marker is global+tmpfs, leaks on SIGKILL/set_time_limit fatal (not
  finally-protected) → path_drift protection silently OFF plugin-wide for remaining uptime;
  promised boot resume has NO implementation.
- **F5** WP#1309 incomplete: ensureAgentMounted still lazy-umounts+rebinds same upper
  (StorageMountService.php:155-161, live poison path); new do_release verb does the same
  unconditionally even on cooldown-skipped bakes and maps exit2→ok=true (latent — zero
  callers yet).
- **F6** The spec'd manifest_write.sh single writer was never built — 3 drifting `php -r`
  copies; the op_bake copy is now the SOLE recorder yet best-effort `|| true`; two comments
  still claim the removed PHP belt-and-braces exists; lifecycle_log fires on failure.
- **F7** Legacy-data guard regression: old unconditional exit-1 is now strict-gated (warn
  mode mounts empty over legacy data) AND the `|| true` sourcing fallback silently becomes
  genuine_fresh, bypassing even strict=1. "No protection removed" was false.
- **F8** layers-stay-flash invariant implemented twice (bash effective_backend vs PHP
  $effCaps) with constructible divergence — UI gates on PHP, engine on bash.
- **F9** Backend probe: ~14-18 subprocess spawns per 5s UI poll (uncached, doubled),
  expensive device probe runs before the cheap layers short-circuit, on every mutating verb.
- **F10** "ANY removable member → flash" implemented for zfs only; btrfs members never
  enumerated → SATA-source+USB-member btrfs pool classifies passthrough.
- **L1-L7** (audit doc): DTO triplication ×4 exit-decode; dead 3rd-arg fallback in
  boot_integrity_classify; defer-marker readers ×4 with peek-vs-consume conflict; umount
  one-liner ×3 vs the arbiter chokepoint; facade verb-depth inconsistency (persist's
  justification comment now false); FileStorageStatus parses 5 keys emit_json never emits;
  pre-existing InstallerService rollback umount -l bypasses the arbiter.
- **Cross-cutting theme:** the epic moved authority into single owners but left each new
  mechanism's write/repair lifecycle unfinished (intents never retired, manifest path never
  re-pointed by migration, single writer = 3 copies). Consolidation = finish the lifecycles,
  not new abstraction.

## Repo / running state
- Branch master @ 63ea12f, up to date with origin. Uncommitted this session: HANDOVER.md +
  docs/audits/arch-review-epic-1310-2026-06-10.md (docs-only; commit via the publish wrapper
  — it auto-routes to commit-only).
- Factory GitLab = v2026.06.09.21. .4 = .21 (running). Storefront NOT promoted (still
  .01-era) — promotion remains deliberate + needs John's confirmation.
- OpenProject 21: Epic #1310 still New; #1321/#1322 (Steps 6/2) shipped but still New;
  #1320/#1323 → Tested. Review findings NOT filed as WPs yet.

## Next logical step
Triage the audit doc with John → file the accepted findings as OP Bugs/Features (suggested
mapping table is at the bottom of the audit doc) → fix top correctness findings F1-F4 via
TDD (each has a concrete fix shape + the L3.5 harness already has SIGKILL/fake-reboot
helpers to regression-test F1's crash windows).

## Active gotchas the next session MUST know (carried forward + new)
- **`migrate-from-geminicli.sh` is DESTRUCTIVE + deliberately DEAD** — never wire it to any
  install/upgrade trigger; `migrateFormat` runs nothing by design.
- **`legacy_unmanaged` is the sibling/backup recovery net** — F7's fix is to STRENGTHEN it
  (halt regardless of strict), never to remove it.
- **Anti-pattern check** blocks `php -r "...\Namespace"` double-quoted — use single-quoted
  `php -d display_errors=0 -r '...'` or a script file (F6's manifest_write.sh fix must
  respect this).
- Publish: ALWAYS `--no-root-sync` (workspace root repo diverged → exit 9 otherwise);
  terse flags `--skip-smoke --skip-l4`, run smoke separately. L3.5 harness not shipped by
  plugin install — hand-scp tests/integration/ to .4.
- Standard scope: mutate only .4; Tower read-only.
- **When fixing F2/F7, mind the verifier nuances** in the audit doc (e.g. F1's masking needs
  ≥1 surviving file — the all-pruned case already halts; F7's default is strict=1 so the
  regression is opt-in except the sourcing-failure path).

## Skills to load first
superpowers:test-driven-development, /jpw-unraid-factory, /jpw-unraid-testing,
/jpw-openproject (for the triage→WP step).

## Files to read before editing storage
- docs/audits/arch-review-epic-1310-2026-06-10.md (this session's findings — the work queue)
- docs/specs/STORAGE_FACADE_IMPLEMENTATION_PLAN.md (esp. lines 130-145, the unbuilt
  manifest_write.sh design F6 resurrects) + docs/00-governance/adr/0001-…md
- src/includes/services/FileStorage.php, BootIntegrityService.php (F1 :272, F4 :336-369)
- src/scripts/storage/{storagectl.sh (F2 :314, F5 :428), storage_ops.sh (F6 :545, F7 :196),
  detect_backend.sh (F9 :139, F10 :81), common.sh (:848)}
- src/scripts/supervisor/aicli-supervisor.sh (F3 :612-622, :664)
