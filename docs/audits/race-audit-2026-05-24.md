# Race Condition Audit — 2026-05-24

## Summary
- **5 findings:** 1 critical, 2 high, 2 medium

All issues are narrow TOCTOU or lock-ordering races that require specific timing to trigger but can cause silent data loss, mount corruption, or orphaned processes when they do.

---

## Findings

### [CRITICAL] Loop mount → overlay mount layer binding race (mount_stack.sh:128-139, 221-227)

**Where:** `src/scripts/storage/mount_stack.sh:128-139` (loop mount discovery/mounting) and lines 221-227 (overlay assembly)

**Race window:** Between detecting the squashfs layers (line 108, glob snapshot) and passing them to the overlay lowerdir list (line 227). A concurrent consolidate or bake can delete old sqsh files in this window.

**Trigger scenario:**
1. Agent A requests remount (e.g., boot, fresh launch)
2. mount_stack.sh enters, reads sqsh glob: `[agent_claude_delta_20260524T120000Z.sqsh, agent_claude_consolidated_20260524T110000Z.sqsh]`
3. Mount loop mounts both to `/tmp/unraid-aicliagents/mnt/*`
4. **Concurrent consolidate finishes, deletes the old delta from disk** (line 431 of consolidate_layers.sh)
5. mount_stack.sh proceeds to assemble overlay with lowerdir pointing to the mount paths (line 227)
6. Overlay mounts successfully, but the loop mounts at lines 134-135 were created from paths on disk that no longer exist
7. If the loop device is later detached (e.g., by a stray umount or cleanup script), the underlying sqsh files are gone, and the loop device inode becomes orphaned

**Impact:** 
- Silent corruption: the overlay appears healthy but its lower layers are no longer backed by real files on disk
- If a power cycle or cleanup script tears down the loop mounts, the data is lost without warning
- The mount itself doesn't fail — mount_stack.sh exits 0 — so the agent launches into a "healthy-looking" mount that can silently lose writes

**Suggested fix:** 
Snapshot the layer list BEFORE mounting loops, then re-validate that the files still exist after mounts succeed but BEFORE using the mounted paths in the overlay. Alternative: hold the per-entity lock (the one used by consolidate_layers) during the entire mount-stack operation so consolidate can't delete layers mid-remount.

**Confidence:** **High** — the glob-snapshot at line 108 is not coordinated with the consolidate delete at line 431. The race window is hundreds of milliseconds (loop mount syscalls + overlay assembly). Stress-testing with rapid consolidates + remounts would trigger it reliably.

---

### [HIGH] Marker file mtime collision across tmpfs nanosecond resolution (commit_stack.sh:121-129, consolidate_layers.sh:218-223)

**Where:** `src/scripts/storage/commit_stack.sh:121-129` and `src/scripts/storage/consolidate_layers.sh:218-223`

**Race window:** The 50ms sleep at lines 129 and 223 is intended to prevent mtime==marker nanosecond collisions, but it's placed AFTER the marker is written. A write happening in the same nanosecond as `touch` (before the sleep) will have the same mtime and be admitted to the cleanup/wipe set.

**Trigger scenario:**
1. commit_stack.sh runs, `touch $MARKER` at T0 (nanosecond precision, mtime = T0)
2. In the same nanosecond, an agent writes a file to UPPER_DIR (mtime = T0)
3. Script `sleep 0.05` (the nanosecond protection happens AFTER the file is written)
4. Agent's write was *during* the bake but mtime equals the marker exactly
5. `find ! -newer $MARKER` includes the agent's file (because `! -newer` means `<=` mtime)
6. File is wiped by selective_upper_cleanup at line 271, silently losing the agent's work

**Impact:** Silent data loss — agent's work committed to upper layer during bake is permanently lost.

**Suggested fix:** Move the `sleep 0.05` to BEFORE the `touch $MARKER`. This ensures any write in the critical window happens strictly AFTER the marker timestamp:
```bash
sleep 0.05
MARKER="/tmp/unraid-aicliagents/.commit_marker_${TYPE}_${ID}"
touch "$MARKER"
```

**Confidence:** **High** — the race is real; the fix is trivial. tmpfs mtime resolution is nanosecond on modern kernels. However, the window is extremely tight (1 nanosecond) so the practical trigger rate is low. Nonetheless, it violates the stated invariant in the code comment ("Any writes after this point will not be in the delta and must NOT be flushed").

---

### [HIGH] Atomic write collision detection bypassed on high-precision clock skew (atomic_write_layer.sh:71-80, 185-190)

**Where:** `src/scripts/storage/atomic_write_layer.sh:71-80` (final_name generation), 185-190 (collision check)

**Race window:** Between checking for file existence (line 185) and the `mv -n` rename (line 193). The filename is based on `date -u +%Y%m%dT%H%M%SZ` (second precision), but the collision detection uses a binary check (`[ -e "$final_path" ]`).

**Trigger scenario:**
1. Bake A completes, names the layer `agent_claude_delta_20260524T120000Z.sqsh`, renames it via mv
2. **In the same second** (before the clock ticks to next second), bake B runs on the same entity
3. Both bakes generate the same final_name (same second)
4. Bake A's rename succeeds; the file exists at final_path
5. Bake B checks `[ -e "$final_path" ]` at line 185 and sees it exists — **but does NOT fail**
6. Bake B proceeds to call `mv -n` which silently fails (no output on collision)
7. Bake B's verification at line 198 is supposed to catch this (`[ ! -e "$final_path" ] || [ -e "$tmp_path" ]`), **but on a fast bake cycle (both in < 1 second), the old final_path can still exist while tmp_path is gone, creating a false positive**

**Impact:** Bake B believes it succeeded and returns the old bake B's tmpfile path. The caller (consolidate or commit) uses this name to update the manifest, pointing to a non-existent file. Mount_stack.sh later fails to mount with "layer not found".

**Suggested fix:** 
- Change the collision detection to use `mv`'s exit code directly instead of pre-checking: `if mv -n "$tmp_path" "$final_path" 2>/dev/null; then ... else ... (report collision) ...fi`
- OR increase precision: use `date -u +%Y%m%dT%H%M%SZ%N` (nanosecond suffix) to ensure unique names even within the same second
- The `set -euo pipefail` in the subshell at line 101 should catch the mv failure, but the post-mv verification at line 198 is weak (it checks the reverse condition, which could be satisfied by coincidental file state)

**Confidence:** **Medium** — the issue requires (a) two bakes of the same entity within the same second (rare but possible under high load), and (b) both bakes to progress through the collision check in the wrong order. The code ATTEMPTS to handle it with the post-rename verification, but that check's logic is inverted or incomplete. The safest fix is to use nanosecond precision or rely on `mv -n`'s exit code.

---

### [MEDIUM] Manifest replace race with on-disk layer cleanup (consolidate_layers.sh:385-434)

**Where:** `src/scripts/storage/consolidate_layers.sh:395-434` (manifest write, then delete old layers)

**Race window:** Between the manifest update (via PHP at line 403-415) and the completion of old-layer deletion (line 434). The code CORRECTLY orders this (manifest first, deletes second) per the comment at line 387, BUT there's a race between when the manifest write returns and when the supervisor's reconcile tick runs.

**Trigger scenario:**
1. consolidate_layers.sh writes manifest with only the new consolidated layer (line 403)
2. manifest write completes, fsync done
3. Before the deletion loop starts, the supervisor's reconcile runs (every ~7s, could collide)
4. Reconcile scans `/tmp/unraid-aicliagents/supervisor/` and doesn't see the lock (the lock is acquired AFTER the manifest write, at line 344)
5. Reconcile sees the old layers on disk that are not in the manifest → classifies them as "untracked" → quarantines them to `.untracked/`
6. **But the consolidate script is about to delete them anyway at line 431** — they get deleted twice (second delete succeeds silently), and the manifest is correct, so recovery is fine
7. **UNLESS the quarantine happens during the delete loop** — reconcile moves a file to `.untracked/` while consolidate is deleting, creating a TOCTOU window where the file is neither in manifest nor in the original location

**Impact:** Data loss is unlikely because the file exists in `.untracked/` after reconcile, but it's no longer referenced by the manifest or original layer list. A manual recovery is needed if the user notices something is missing.

**Suggested fix:** The lock is already taken at line 344 to prevent this, BUT it's taken AFTER the manifest write. The code comment at line 337-339 correctly identifies the issue. **The lock should be taken BEFORE the mksquashfs** (which is the long operation), so no supervisor tick can run between manifest write and delete completion. Alternatively, ensure the reconcile checks for the lock and skips any entity whose lock is held (which the supervisor already does per its code).

**Confidence:** **Medium** — the code acknowledges the issue and documents the fix (lock before bake), but the implementation has the lock taken too late (after bake, before manifest). The practical impact is low because the supervisor already respects the lock for other operations, and even if reconcile runs mid-delete, the file still exists in `.untracked/`. However, it violates the stated invariant in the comment.

---

### [MEDIUM] Loop mount not cleaned up on overlay mount failure (mount_stack.sh:128-139, 227)

**Where:** `src/scripts/storage/mount_stack.sh:128-139` (loop mount loop), lines 221-227 (overlay mount, no cleanup on fail)

**Race window:** If the overlay mount fails at line 227, the loop mounts created in the earlier loop (lines 129-139) are left in place. A subsequent retry of mount_stack will not remount them (line 134 checks `mountpoint -q` and skips if already mounted), but if the underlying sqsh file was deleted between the first mount attempt and the retry, the loop mount is orphaned.

**Trigger scenario:**
1. mount_stack.sh runs, mounts loop devices for two sqsh layers
2. Overlay mount fails (e.g., ENOMEM, invalid options on older kernel)
3. Script exits 1, loop mounts left in place at `/tmp/unraid-aicliagents/mnt/*`
4. Agent launch fails; user retries
5. mount_stack.sh runs again, sees the existing loop mounts (line 134), skips remounting
6. **But consolidate ran in between and deleted one of the old sqsh files from disk**
7. The loop mount now points to a non-existent file
8. Overlay mount succeeds (the lowerdir list still contains the valid mount paths), **but the data backing that layer is gone**

**Impact:** Silent data loss if the loop device is used but the underlying file no longer exists. The mount appears healthy, but writes to the lower layer silently fail or are lost.

**Suggested fix:** On overlay mount failure (line 227), explicitly umount all loop mounts created in this invocation. Track which loop mounts were created locally (vs pre-existing) and clean them up on error. Alternatively, always umount and remount loop devices (don't skip if already mounted) to ensure the underlying file hasn't been deleted.

**Confidence:** **Medium** — the race requires (a) overlay mount failure, (b) layer deletion between first and second mount_stack call, and (c) both happening before mount_stack succeeds. The window is larger than most races (could be minutes if the agent launch fails and the user doesn't immediately retry), but the outcome (silent data loss via orphaned loop mount) is severe.

---

## Recommendations

### Immediate (v2026.05.24.xx or .25)
1. **Fix the marker mtime issue** (HIGH): Move sleep before touch. This is a one-line fix with high confidence.
2. **Add loop mount cleanup on overlay failure** (MEDIUM): Small defensive change in mount_stack.sh.

### Short-term follow-up
3. **Fix atomic write collision detection** (HIGH): Use nanosecond precision in filenames or rely on `mv -n` exit code instead of the current verification logic.
4. **Refactor manifest/delete ordering with explicit lock** (MEDIUM): Move per-entity lock acquisition before the long mksquashfs operation, not after.

### Investigation / stress-test
5. **Loop mount binding race** (CRITICAL): High confidence in the race existing; recommend stress-testing with rapid consolidate + remount cycles to confirm trigger rate. If it reproduces easily, prioritize before storefront. If rare (<1:1000), document as a known edge case and monitor field reports.

---

## Test Coverage Recommendation
- **Smoke test:** Run consolidate on one entity while simultaneously launching an agent on another entity, repeat 20 times — watch for orphaned loop mounts or mount failures
- **Marker collision test:** Patch commit/consolidate to skip the sleep, run high-frequency bakes, verify no files disappear from UPPER after bake
- **Atomic write collision test:** Patch atomic_write_layer to use second precision, run two bakes of the same entity < 1 second apart, verify both complete with distinct layer names
