# ADR 0001 — A single, authoritative filestorage management component

**Status:** Proposed
**Date:** 2026-06-08
**Deciders:** John White
**Related:** Epic (OpenProject — storage lifecycle), WP #1309 (first slice), #1302/#1277/#1278/#1246/#1263 (the hardening cycle this builds on)

## Context

The OverlayFS / ZRAM / SquashFS storage subsystem performs four core mutations — **mount**,
**bake** (ZRAM→SquashFS persist), **consolidate** (merge layers), and **reclaim** (trim the ZRAM
upper after a confirmed bake). Today these live in a bash core (`storage_ops.sh` op_mount/op_bake/
op_consolidate, dispatched by `storagectl.sh`, with shared helpers in `common.sh`) and are driven by
several consumers (PHP `StorageMountService`, the supervisor, the `event/` hooks, the installer,
`TaskService`).

A consumer-mapping pass (2026-06-08) found the core is **half-centralised**: the pieces exist, but

1. **It is not authoritative.** Some consumers bypass it — `event/stopping` sources
   `atomic_write_layer` directly rather than going through the bake path; PHP re-derives upper paths.
   When the centre is optional, the safety it encodes (bake-confirmed reclaim #1277, completeness
   guard #1278, busy-arbitration) can be silently skipped by a new or hand-rolled caller.
2. **It does not DRY its own discipline.** `op_bake` and `op_consolidate` each re-implement the same
   guarded-mutation skeleton: *busy-arbitrate → (defer | mutate) → refresh mount → conditionally
   reclaim upper → lifecycle-log*. Because the skeleton is copied, the safety steps can drift —
   which they have (see below).

### Verified duplication (confirmed against source — not the raw over-flagged audit)

| # | Finding | Status |
|---|---|---|
| 1 | `op_mount` has no busy-guard; on a busy mount it lazy-umounts + immediately rebinds the same upper/work → copy-up poison (ENOENT) | **REAL** → WP #1309 |
| 2 | `op_consolidate` busy-check is bare `fuser -sm` (storage_ops.sh:717,1101); `op_bake` uses session-aware `home_mount_in_use` (353,561) with a comment that bare fuser MISSES live ttyd sessions. Same gap, different function | **REAL** |
| 3 | The guarded-mutation skeleton is copy-pasted across `op_bake`/`op_consolidate` (and a third hand-rolled bake in `event/stopping`) | **REAL** |
| 4 | PHP `StorageMountService::resolveHomeUpperPath` (291-302) replicates bash `_entity_paths` (common.sh) fstype→upper logic | **REAL** (maintenance) |
| 5 | Defer-reason vocabulary is split across `common.sh` (write), `storagectl.sh` (read), `TaskService.php` (user message) — no single enum | **REAL** (robustness) |

### Explicitly NOT issues (over-flagged by the mapping pass — recorded so they are not re-chased)

- ❌ "`op_consolidate` never cleans the upper / data loss." It **does** wipe the upper after the
  refresh (storage_ops.sh ~1128-1136), guarded by an `UPPER_CHANGED` check (D-353) that preserves
  the upper if writes arrived after the bake marker. A full wipe is *correct* there — after
  consolidation everything is in the merged lower. Intentional and safe.
- ❌ "`event/stopping` hand-rolled bake = data-safety blind spot." It is a shutdown delta-bake that
  persists to Flash via the atomic writer; **no reclaim is needed** because the box is going down and
  the ZRAM upper is about to vanish anyway. Safe (it is, however, a third bake entry-point — see
  decision 4).
- ❌ "Consolidate verdict computed twice." The supervisor's `_check_consolidate_policy`
  (aicli-supervisor.sh:1246) **shells to `storagectl status` and consumes its `recommended`
  verdict** — single source of truth, supervisor is a thin consumer.

## Decision

**One component owns all filesystem activity behind a small, intent-level interface.** Consumers
(TerminalService, the supervisor, the `event/` hooks, the installer, the UI) express *what they
want* — not *how storage works*. Everything below the interface is **private** to the component:
OverlayFS assembly, the ZRAM upper, SquashFS bake, consolidate, reclaim, **the layer manifest**,
layer discovery/ordering, boot-integrity classification, the reconcile loop, locks, and
busy-arbitration. A consumer can no longer reach a file, the manifest, or a mount directly — so it
cannot desync them, and it carries **none** of the storage complexity. (This is the principle; the
"authoritative + DRY" mechanics below are how the owner upholds it internally.)

### Consumer-facing interface (intent-level — the WHOLE public surface)

A handful of high-level operations, e.g.:

- **`ensureReady(entity)`** — the entity's storage is mounted and writable for use (subsumes
  `ensureHomeMounted` / `ensureAgentMounted` + mount assembly + boot-integrity + ownership chown).
  Returns *ready / deferred / unavailable*; assembling layers is never the caller's job.
- **`persist(entity)`** — flush this entity's in-RAM changes to durable storage (subsumes *bake*).
  Idempotent; self-defers when busy.
- **`release(entity)`** — tear down for shutdown/close (subsumes unmount + final flush).
- **`status(entity)`** — health / dirty bytes / consolidate-recommended, for the UI + supervisor.

Maintenance (**consolidate, reclaim, reconcile, manifest upkeep**) is *internal and self-scheduled* —
**not** a consumer operation. A consumer never says "consolidate" or "record this layer"; it says
`persist`, and the owner decides the rest. The drift bugs this session (#1314/#1315/#1318/#1319) were
all consumers — or a human — touching the manifest/files behind the owner's back; the interface makes
that impossible by construction.

### Private internals (the storage complexity, hidden behind the interface)

The four mechanics below are now **implementation details of the one owner**, not contracts spread
across callers:

1. **One busy-arbiter.** A single function answers "is it safe to (re)build / reclaim this mount?"
   combining *session-awareness* (`home_mount_in_use` for `type=home`) **and** *kernel-busy*
   (umount-failure). All of mount/bake/consolidate use it; no caller hand-rolls `fuser`. This closes
   #1309 and finding #2 at once (they are the same root cause).

2. **One guarded-mutation template.** Extract the *busy-arbitrate → defer | mutate → refresh →
   conditional-reclaim → lifecycle-log* skeleton into a single template that `op_bake`,
   `op_consolidate`, and `op_mount` use. The safety discipline lives in one place and cannot be
   forgotten or partially copied.

3. **Authoritative entry + collapsed derivations + the manifest is a PRIVATE index (#1320).**
   Every bake/consolidate/reclaim routes through the component (route `event/stopping`'s shutdown
   bake through the central bake path). Remove the PHP path replica (`resolveHomeUpperPath` → call
   the bash resolver) and define a single defer-reason enum in `common.sh`, consumed by PHP. And
   crucially: **every layer create/delete updates the files AND the manifest under the same entity
   lock** — nothing outside the owner writes the manifest or deletes a layer file, with a
   write-ahead intent log so a crash is unambiguous (intentional prune vs real loss). This collapses
   the three drift mechanisms (the `addLayer` API, the 7 s reconcile loop, and the boot-integrity
   heuristics) back into one invariant the owner keeps. PHP/events/supervisor/installer keep only
   their genuinely consumer-specific policy.

4. **`exit 2 = deferred/busy` stays the lingua franca.** It is already the convention (op_bake,
   op_consolidate, storagectl `do_mount`). No new exit code. Consumers react to it with their one
   consumer-specific deferral line (skip reclaim / skip wipe / return success).

### Storage backend strategy (internal — flash vs passthrough; consumers never see it)

The device dictates whether the wear-sparing machinery is needed at all; the owner picks the backend
and consumers never branch on it. There are exactly **two** backends:

- **`flash`** (a removable USB stick — Unraid's boot device): the full LAYERING ENGINE — ZRAM upper +
  SquashFS lowers + bake/consolidate/reclaim + the manifest. This exists *only* to spare Flash wear.
- **`passthrough`** (any fixed durable device — HDD/SSD/NVMe/array): a **plain directory** written
  directly. NO overlay, NO ZRAM, NO SquashFS, NO bake/consolidate/reclaim, NO manifest, NO
  boot-integrity. `persist` is a no-op/`fsync`; `status.supportsBake = false`.

A hybrid **`durable-overlay`** (disk-backed upper but still SquashFS+bake) was considered and
**rejected**: it pays the entire layering complexity — and every drift bug that comes with it — for
**zero** wear benefit on a device that doesn't need it. Two backends, not three.

**Backend selection must be a genuine device test, not the fstype proxy.** Today's `vfat → flash`
(`_entity_paths` → `ENTITY_UPPER_MODE`, replicated in PHP `resolveHomeUpperPath`) is a heuristic — a
vfat-formatted SSD is not a stick, and the wear-limited stick is the one thing we must protect. The
selector needs `isFlashDevice(path)`: resolve path → mount → backing block device → flash ONLY when
the device is removable / on the USB transport (`/sys/block/<dev>/removable`, `lsblk -o
TRAN,RM,HOTPLUG`). **Err toward `flash` when uncertain** — mis-treating a real stick as passthrough
wears it out (bad); mis-treating a fixed disk as flash only wastes RAM/complexity (harmless). (WP for
the detector under #1310.)

**Rollback on durable storage** — the value the dropped hybrid seemed to offer (#964/#965 pre-upgrade
snapshots) — comes from the durable filesystem's OWN snapshots (zfs/btrfs), not SquashFS deltas. A
separate concern, not a reason to keep an overlay on durable storage.

**The facade is identical across both backends, and exposes capabilities for the UI.** `ensureReady` /
`persist` / `release` are the same call regardless of device; `status(entity)` returns
`{ state, backend, isDurable, supportsBake, supportsConsolidate, dirtyBytes }`, and the UI renders the
Bake/Consolidate controls purely from `supportsBake`/`supportsConsolidate` — so on a passthrough device
those buttons simply don't appear, with zero storage logic in the UI. `backend`/`storageType` is
**auto-detected** by the owner (via `isFlashDevice`), not a user setting.

### Migration is a bounded concern with exactly TWO triggers (never in the hot path)

Migration must not be interleaved with normal operation. Today it is scattered — per-mount legacy
probing (`op_mount` `LEGACY_FOUND` / D-298/D-342), boot-integrity `legacy_unmanaged`/`path_drift`
states, the `migrate-from-geminicli` / `migrate-btrfs-to-squashfs` scripts, and the reconcile loop
all carry migration into every boot/mount. Consolidate it to **two explicit, known moments**, owned
by the component:

1. **Persist / agent path change** — the user edits the storage path in settings → migrate the data
   from the old path to the new, then re-point the component. This *replaces* the passive `path_drift`
   detect-and-halt: the settings layer **tells** the component "path X → Y, migrate," so a normal boot
   never discovers a drifted path to halt on.
2. **Plugin version upgrade** — install of a newer version → run any on-disk format/layout migration
   ONCE (btrfs→squashfs, geminicli→aicliagents, seq-keying, …). The installer triggers it; afterward
   the data is current-format.

Between triggers, the operate-path (`ensureReady` / `persist` / boot-integrity) **assumes**
current-format, current-path data — no per-mount legacy probing. **Crash-safety:** a migration writes
an in-progress / completed **migration marker** (same write-ahead discipline as the manifest), so an
interrupted migration is resumed/completed at the next boot instead of tripping normal operation over
half-migrated data.

The split this clarifies: **migration-detection** states (`legacy_unmanaged`, `path_drift`) move to
the two triggers and leave the hot path; **genuine loss/corruption** states (`partial_loss`,
`total_loss`, `host_mismatch`) stay as flash-backend integrity checks. Migration moves data *on
purpose*; integrity catches data going missing *by accident* — they are different jobs and should not
share the boot path.

### What remains genuinely consumer-specific (must NOT be centralised)

Session-path classification (which sessions hold the array, `event/stopping`), emergency-mode setup,
the terminal startup sequence, the agent-vs-home consolidate *strategy* choice, and the supervisor's
queue / rate-limit / timeout / notify orchestration. These are real per-caller policy.

## Target-state component diagram

```
  CONSUMERS  (express WHAT they want — carry ZERO storage complexity)
  ┌──────────────────────────────────────────────────────────────────────────┐
  │  TerminalService   Supervisor   event/ hooks   Installer   UI / AJAX       │
  └─────────────────────────────────────┬──────────────────────────────────────┘
                                         │  intent calls + read capability props
                                         ▼
  ┌────────────────────────────────────────────────────────────────────────────┐
  │  PUBLIC INTERFACE — the entire public surface                               │
  │    ensureReady(entity) · persist(entity) · release(entity) · status(entity) │
  │    status → { state, backend, isDurable, supportsBake,                      │
  │               supportsConsolidate, dirtyBytes }   ◄── UI renders FROM these  │
  └─────────────────────────────────────┬──────────────────────────────────────┘
  ══════════════════════════════════════╪═══ privacy boundary: all PRIVATE below ══
                                         ▼
  ┌────────────────────────────────────────────────────────────────────────────┐
  │  FILESTORAGE COMPONENT   (sole owner of every filesystem mutation)           │
  │                                                                              │
  │   ┌─ backend selector:  isFlashDevice(path) ? ─────────────────────────────┐ │
  │   │    removable / USB transport — NOT fstype.   uncertain → FLASH (safe)   │ │
  │   └──────────────┬─────────────────────────────────────────┬───────────────┘ │
  │       yes (stick)▼                          no (HDD/SSD/NVMe/array)▼          │
  │   ┌──────────────────────────────────────┐   ┌──────────────────────────┐   │
  │   │ FLASH backend                         │   │ PASSTHROUGH backend       │   │
  │   │   persist = real SquashFS bake        │   │   persist = no-op / fsync  │   │
  │   │   supportsBake/Consolidate = TRUE     │   │   supportsBake = FALSE     │   │
  │   │ ┌──────────────────────────────────┐  │   │   plain directory; direct  │   │
  │   │ │ LAYERING ENGINE (Flash-wear ONLY) │  │   │   writes — NO overlay,     │   │
  │   │ │  • busy-arbiter (session+kernel)  │  │   │   NO ZRAM, NO SquashFS,    │   │
  │   │ │  • guarded-mutation template      │  │   │   NO bake, NO manifest,    │   │
  │   │ │  • op_mount/op_bake/op_consolidate │  │   │   NO boot-integrity        │   │
  │   │ │  • MANIFEST = private index       │  │   └──────────────────────────┘   │
  │   │ │     file+manifest: 1 lock + WAL   │  │    rollback (if wanted) = the     │
  │   │ │  • boot-integrity + reconcile     │  │    device fs's OWN snapshots       │
  │   │ │    ▲ drift bugs #1314/15/18/19    │  │    (zfs/btrfs), NOT squashfs       │
  │   │ │      live ONLY here (backstops)   │  │                                    │
  │   │ └───────┬───────────────┬──────────┘  │                                    │
  │   │         ▼               ▼             │                                    │
  │   │  ┌────────────┐  ┌────────────────┐   │                                    │
  │   │  │ ZRAM upper │  │ SquashFS lowers│   │                                    │
  │   │  │  (RAM)     │  │ .sqsh deltas   │   │                                    │
  │   │  └────────────┘  └────────────────┘   │                                    │
  │   └──────────────────────────────────────┘                                    │
  └────────────────────────────────────────────────────────────────────────────┘
```

**Reading the diagram**
- **Consumers → interface only.** No consumer constructs a mount, writes a `.sqsh`, or touches the
  manifest. They call four intent verbs and read capability props from `status` (e.g. `supportsBake`).
- **Privacy boundary (the `═══` line).** Everything below is the owner's private business — there is
  no path for a caller (or a human) to desync files vs manifest.
- **Two backends, auto-selected by `isFlashDevice(path)`** — a *genuine* removable/USB-transport test,
  not the fstype proxy; **uncertain → FLASH**, because mis-treating a real stick as passthrough wears
  it out. Consumers never branch on the device.
- **FLASH = the whole layering engine** (it exists *only* to spare Flash wear). **PASSTHROUGH = a
  plain directory, direct writes** — none of the layering, the manifest, or boot-integrity even
  exists. `persist` is a real bake on one, a no-op on the other; the caller never knows which.
- **The manifest is a private index** (Flash only), updated in the *same* locked op as the layer files
  (+ write-ahead intent log), collapsing the three drift mechanisms (the `addLayer` API + the 7 s
  reconcile loop + boot-integrity heuristics) into one owner-kept invariant.
- **The UI renders from `status`.** Bake/Consolidate buttons appear iff `supportsBake` /
  `supportsConsolidate` — so they vanish on a passthrough device, with zero storage logic in the UI.
- **Every drift bug this session hardened is Flash-specific.** A passthrough device incurs none of it
  — the cleanest proof the complexity belongs *inside* one owner, not spread across callers or tiers.

## Consequences

**Positive**
- A consumer can no longer cause a copy-up poison or an ENOENT by forgetting a check — the worst it
  can do is mishandle a clearly-signalled `exit 2`.
- The busy-detection inconsistency (#2) disappears: one definition of "busy" everywhere.
- New storage callers get the safety discipline for free by calling the component.

**Negative / risk**
- This refactors a **freshly-stabilised, data-loss-sensitive** subsystem (just through #1277/#1278/
  #1246). A big-bang rewrite is exactly how a data-loss regression is reintroduced.

**Mitigation — contract-first, incremental migration.** This ADR is the contract. Migrate one
consumer per change, each behind the existing L2 + L3.5 tests (reclaim/consolidate completeness must
stay green), each independently reviewable. No sweep.

## Migration slices (→ OpenProject)

1. **#1309** — `op_mount` busy-guard + introduce the **single busy-arbiter** primitive. (First slice;
   delivers the arbiter the rest build on.) Spec: `docs/specs/OP_MOUNT_BUSY_REMOUNT_SAFETY.md`.
2. Migrate `op_consolidate` onto the shared busy-arbiter (kills the bare-`fuser` gap, finding #2).
3. Extract the **guarded-mutation template**; migrate `op_bake` + `op_consolidate` onto it (finding #3).
4. Collapse duplicated derivations: PHP `resolveHomeUpperPath` → bash resolver; single defer-reason
   enum (findings #4, #5). Route `event/stopping` bake through the central path.

## Verification (per slice)
- Pure-function units for the arbiter and any extracted decision (red→green).
- L3.5 integration on .4 exercising the real busy path (hold an fd, trigger the mutation, assert no
  ENOENT + mount-stays-live + idle-refresh-succeeds).
- Full L2 + L3.5 regression green, especially reclaim_completeness / consolidate_completeness.
