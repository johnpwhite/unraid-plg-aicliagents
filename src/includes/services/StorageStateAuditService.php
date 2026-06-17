<?php
/**
 * <module_context>
 *     <name>StorageStateAuditService</name>
 *     <description>The PROGRAMMATIC state-invariant auditor (Feature #1382): the live-box twin of the
 *     tests/lib/state-ledger.sh harness ledger. Produces ONE integrity report covering the same five
 *     invariants the bash ledger asserts in the test layers, so a state-integrity regression surfaces
 *     identically whether it is found by a smoke/L3.5/soak run (bash) or by the running plugin
 *     (this service): I1 orphan overlay mount (mounted-with-layers entity absent from the manifest),
 *     I2 manifest&lt;-&gt;disk drift in BOTH directions (recorded layer missing on disk; on-disk .sqsh
 *     untracked), I3 stale defer/bake markers past their TTL, I4 supervisor single-instance + pidfile
 *     live + heartbeat fresh, I5 orphan loop devices bound to deleted .sqsh AND not currently
 *     mounted/referenced by a live overlay (an in-use deleted lower is benign deleted-but-open Unix
 *     semantics, NOT an orphan — only a truly-abandoned, unmounted deleted-backing loop is flagged).</description>
 *     <dependencies>HealthService, SupervisorService, LayerManifestService, StorageMountService, StoragePathResolver, ConfigService</dependencies>
 *     <constraints>FACTORING (single source of truth — see audit() docblock): this service REUSES
 *     HealthService's PURE evaluators (evalSupervisor / evalMounts / evalDeferMarkers) for the three
 *     decisions HealthService already models, and adds ONLY the deeper checks HealthService's cheap
 *     60s-cached collectors deliberately omit (multi-instance proc count via SupervisorService::daemonPids,
 *     both-direction manifest&lt;-&gt;disk drift, orphan-loop via losetup). HealthService is NOT made heavier:
 *     it keeps its cached fast path; this service is the deeper consumer invoked on demand (diagnostics
 *     bundle + release gate). Never throws (per-check fault isolation). Read-only: enumerates /proc/mounts,
 *     losetup, the manifest, and the persist tree — mutates nothing. Honours the same AICLI_* test hooks
 *     as the resolvers (AICLI_MANIFEST_PATH etc). See docs/specs/STATE_INVARIANT_HARNESS.md.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageStateAuditService {

    public const SCHEMA_VERSION = 1;

    /** Categories (kept byte-identical to the bash ledger invariant ids). */
    public const CAT_ORPHAN_MOUNT = 'orphan-mount';   // I1
    public const CAT_MANIFEST_DRIFT = 'manifest-drift'; // I2
    public const CAT_STALE_MARKER = 'stale-marker';   // I3
    public const CAT_SUPERVISOR   = 'supervisor';     // I4
    public const CAT_ORPHAN_LOOP  = 'orphan-loop';    // I5

    /**
     * Run the full integrity audit. Returns:
     *   { ok:bool, schema:int, generated_at:string,
     *     violations:[ {category, detail} ],
     *     counts:{ sqsh, manifest_entities, overlay_mounts, loops, markers, supervisor_procs } }
     *
     * FACTORING DECISION (documented per the spec): HealthService already carries
     * the PURE decision logic for "supervisor live + heartbeat fresh"
     * (evalSupervisor), "mounted-with-layers must be manifest-tracked"
     * (evalMounts — the I1 shape), and "defer markers past TTL" (evalDeferMarkers
     * — the I3 shape). To keep ONE source of truth we delegate those three
     * decisions to HealthService's evaluators here, and implement ONLY the three
     * deeper checks HealthService deliberately omits for its 60s-cached cheap
     * path: (a) supervisor MULTI-instance (proc count > 1) via
     * SupervisorService::daemonPids(); (b) BOTH-direction manifest<->disk drift
     * (HealthService::evalMounts only checks mounted entities one way); (c)
     * orphan loop devices on deleted .sqsh. HealthService is left untouched on
     * its fast path — this service is the deeper, on-demand consumer.
     */
    public static function audit(): array {
        $violations = [];
        $counts = [
            'sqsh' => 0, 'manifest_entities' => 0, 'overlay_mounts' => 0,
            'loops' => 0, 'markers' => 0, 'supervisor_procs' => 0,
        ];

        // Shared, cheap reads (each fault-isolated).
        $persistRoots = self::persistRoots();
        $onDisk       = self::onDiskSqsh($persistRoots);          // basename => true
        $counts['sqsh'] = count($onDisk);
        $manifest     = self::manifestEntities();                  // entity => [layer filenames]
        $counts['manifest_entities'] = count($manifest);
        $mounts       = self::pluginOverlayMounts();               // entity => path
        $counts['overlay_mounts'] = count($mounts);

        // ---- I2 manifest <-> disk drift (both directions) -------------------
        try {
            $trackedSet = [];
            foreach ($manifest as $entity => $layers) {
                foreach ($layers as $fn) {
                    if ($fn === '') continue;
                    $trackedSet[$fn] = true;
                    if (!isset($onDisk[$fn])) {
                        $violations[] = ['category' => self::CAT_MANIFEST_DRIFT,
                            'detail' => "recorded layer missing on disk: $entity -> $fn"];
                    }
                }
            }
            foreach (array_keys($onDisk) as $fn) {
                if (!isset($trackedSet[$fn])) {
                    $violations[] = ['category' => self::CAT_MANIFEST_DRIFT,
                        'detail' => "on-disk .sqsh not tracked by manifest: $fn"];
                }
            }
        } catch (\Throwable $e) {
            $violations[] = ['category' => self::CAT_MANIFEST_DRIFT, 'detail' => 'check errored: ' . $e->getMessage()];
        }

        // ---- I1 orphan overlay mounts (REUSE HealthService::evalMounts shape) -
        // Build the evalMounts input: entity => (has persisted layers on disk).
        try {
            $mountedWithLayers = [];
            foreach ($mounts as $entity => $_path) {
                [$type, $id] = array_pad(explode('/', $entity, 2), 2, '');
                $mountedWithLayers[$entity] = self::entityHasLayers($type, $id, $onDisk);
            }
            $res = HealthService::evalMounts($mountedWithLayers, array_keys($manifest));
            if (($res['status'] ?? '') === HealthService::STATUS_FAIL) {
                $violations[] = ['category' => self::CAT_ORPHAN_MOUNT, 'detail' => (string)($res['message'] ?? 'orphan mount')];
            }
        } catch (\Throwable $e) {
            $violations[] = ['category' => self::CAT_ORPHAN_MOUNT, 'detail' => 'check errored: ' . $e->getMessage()];
        }

        // ---- I3 stale markers (REUSE HealthService::evalDeferMarkers) --------
        // HealthService warns at 6h; the invariant fails at the bash TTL (24h
        // default, AICLI_DEFER_MARKER_TTL_H override) — so we feed the SAME
        // collected ages but apply the harness TTL for the hard violation.
        try {
            $ttl = self::deferTtlSeconds();
            $stale = [];
            foreach (self::deferMarkerAges() as $name => $age) {
                if ($age >= $ttl) $stale[] = "$name ({$age}s > {$ttl}s)";
            }
            $counts['markers'] = count(self::deferMarkerAges());
            if ($stale !== []) {
                $violations[] = ['category' => self::CAT_STALE_MARKER, 'detail' => 'stale defer/bake marker(s): ' . implode(', ', $stale)];
            }
        } catch (\Throwable $e) {
            $violations[] = ['category' => self::CAT_STALE_MARKER, 'detail' => 'check errored: ' . $e->getMessage()];
        }

        // ---- I4 supervisor: single-instance + live + fresh ------------------
        // Uses SupervisorService::singleInstanceHealth() — the SAME definition
        // the #1381 self-heal (ensureHealthy) acts on, so the audit and the
        // healer agree. The flock guarantees ONE active supervisor: a live
        // pidfile owner with a fresh heartbeat is single-instance EVEN with
        // extra path-matching procs (the forked heartbeat subshell, an in-flight
        // work child, a transient losing `start`). A genuine wedge is procs
        // present with NO healthy owner OR a stale tick. We additionally reuse
        // HealthService::evalSupervisor (its pure decision) so a "should be
        // running but isn't" box is caught too.
        try {
            $config  = ConfigService::getConfig();
            $enabled = (string)($config['supervisor_enabled'] ?? '1') === '1';
            $tick    = max(1, (int)($config['supervisor_tick_seconds'] ?? 5));
            $sih = SupervisorService::singleInstanceHealth();
            $counts['supervisor_procs'] = (int)$sih['procs'];
            if (!$sih['healthy']) {
                $violations[] = ['category' => self::CAT_SUPERVISOR,
                    'detail' => 'single-instance/health violation: ' . (string)$sih['reason']
                        . ' (' . (int)$sih['procs'] . ' proc(s))'];
            }
            // HealthService's pure live+heartbeat decision, reused: catches the
            // "enabled + should be running but no live owner" case. De-dup: skip
            // when singleInstanceHealth already flagged the unhealthy state.
            $res = HealthService::evalSupervisor($enabled, (bool)$sih['running'], $sih['tick_age'], $tick);
            if (($res['status'] ?? '') === HealthService::STATUS_FAIL && $sih['healthy']) {
                $violations[] = ['category' => self::CAT_SUPERVISOR, 'detail' => (string)($res['message'] ?? 'supervisor fault')];
            }
        } catch (\Throwable $e) {
            $violations[] = ['category' => self::CAT_SUPERVISOR, 'detail' => 'check errored: ' . $e->getMessage()];
        }

        // ---- I5 orphan loop devices on deleted .sqsh ------------------------
        try {
            $orphans = self::orphanLoops();
            $counts['loops'] = self::pluginLoopCount();
            if ($orphans !== []) {
                $violations[] = ['category' => self::CAT_ORPHAN_LOOP,
                    'detail' => count($orphans) . ' loop device(s) bound to deleted .sqsh: ' . implode(', ', array_slice($orphans, 0, 25))
                        . (count($orphans) > 25 ? ' …(+' . (count($orphans) - 25) . ' more)' : '')];
            }
        } catch (\Throwable $e) {
            $violations[] = ['category' => self::CAT_ORPHAN_LOOP, 'detail' => 'check errored: ' . $e->getMessage()];
        }

        return [
            'schema'       => self::SCHEMA_VERSION,
            'ok'           => $violations === [],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'violations'   => $violations,
            'counts'       => $counts,
        ];
    }

    // ==================================================================
    // Collectors (read-only; honour the same hooks the resolvers honour)
    // ==================================================================

    /** @return string[] distinct existing persist roots (home + agent). */
    private static function persistRoots(): array {
        $roots = [];
        foreach ([StoragePathResolver::homePersistPath(''), StoragePathResolver::agentPersistPath()] as $p) {
            $p = rtrim((string)$p, '/');
            if ($p !== '' && is_dir($p) && !in_array($p, $roots, true)) $roots[] = $p;
        }
        return $roots;
    }

    /** @param string[] $roots @return array<string,bool> basename => true */
    private static function onDiskSqsh(array $roots): array {
        $out = [];
        foreach ($roots as $r) {
            foreach (@glob("$r/*.sqsh") ?: [] as $f) {
                $out[basename($f)] = true;
            }
        }
        return $out;
    }

    /** @return array<string,string[]> entity => recorded layer filenames. */
    private static function manifestEntities(): array {
        $out = [];
        foreach (LayerManifestService::getAllEntities() as $entity => $ent) {
            $layers = is_array($ent['expected_layers'] ?? null) ? $ent['expected_layers'] : [];
            $names = [];
            foreach ($layers as $l) {
                $fn = (string)($l['filename'] ?? '');
                if ($fn !== '') $names[] = $fn;
            }
            $out[(string)$entity] = $names;
        }
        return $out;
    }

    /** Does an on-disk .sqsh exist for <type>_<id>_*? @param array<string,bool> $onDisk */
    private static function entityHasLayers(string $type, string $id, array $onDisk): bool {
        $prefix = $type . '_' . $id . '_';
        foreach (array_keys($onDisk) as $fn) {
            if (strncmp($fn, $prefix, strlen($prefix)) === 0) return true;
        }
        return false;
    }

    /**
     * Plugin overlay merge mounts from /proc/mounts -> entity => mountpoint.
     * Mirrors HealthService::collectMounts' parse exactly (home work dir +
     * agent mnt base), the same regex source of truth.
     *
     * @return array<string,string>
     */
    private static function pluginOverlayMounts(): array {
        $mounts = is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '';
        $out = [];
        $root = preg_quote(rtrim(StoragePathResolver::MOUNT_ROOT, '/'), '#');
        if (preg_match_all('#^\S+\s+(' . $root . '/work/([^/\s]+)/home)\s+overlay\b#m', $mounts, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) $out['home/' . $row[2]] = $row[1];
        }
        $agentBase = preg_quote(StorageMountService::AGENT_MNT_BASE, '#');
        if (preg_match_all('#^\S+\s+(' . $agentBase . '/([^/\s]+))\s+overlay\b#m', $mounts, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) $out['agent/' . $row[2]] = $row[1];
        }
        return $out;
    }

    /** Defer-marker TTL in seconds: AICLI_DEFER_MARKER_TTL_H > cfg > 24h. */
    private static function deferTtlSeconds(): int {
        $env = getenv('AICLI_DEFER_MARKER_TTL_H');
        $h = ($env !== false && ctype_digit((string)$env) && (int)$env > 0) ? (int)$env : null;
        if ($h === null) {
            $cfg = ConfigService::getConfig()['defer_marker_ttl_h'] ?? null;
            $h = (is_numeric($cfg) && (int)$cfg > 0) ? (int)$cfg : 24;
        }
        return max(1, $h) * 3600;
    }

    /** @return array<string,int> defer/bake marker basename => age seconds. */
    private static function deferMarkerAges(): array {
        $ages = [];
        $now = time();
        $rt = getenv('AICLI_RUNTIME_DIR') ?: StoragePathResolver::MOUNT_ROOT;
        $rt = rtrim($rt, '/');
        foreach (@glob("$rt/.bake_defer_reason_*") ?: [] as $f) {
            $mt = @filemtime($f);
            if ($mt !== false) $ages[basename($f)] = max(0, $now - $mt);
        }
        foreach (@glob('/var/run/aicli-bake-*.lock') ?: [] as $f) {
            $mt = @filemtime($f);
            if ($mt !== false) $ages[basename($f)] = max(0, $now - $mt);
        }
        return $ages;
    }

    /**
     * losetup -a parse -> basenames of GENUINELY-orphan loop devices: a loop
     * bound to a DELETED .sqsh backing file that is ALSO not currently mounted
     * or referenced by a live overlay.
     *
     * REFINED (Feature #1382, finding I5 — kept byte-equivalent in definition to
     * tests/lib/state-ledger.sh's I5): a loop on a deleted .sqsh is NOT an orphan
     * if the loop device is still mounted (a /proc/mounts source) OR referenced
     * as a lowerdir/upperdir/workdir of a live overlay. After a consolidate/bake
     * the old layer .sqsh is deleted while a LIVE agent overlay still has that
     * layer loop-MOUNTED as a lower — benign deleted-but-open Unix semantics that
     * clears on the overlay's next remount/close. Only a deleted-backing loop
     * that is NOT mounted anywhere is truly abandoned residue (the do_wipe
     * loop-teardown bug). In-use deleted lowers are benign and not flagged.
     *
     * @return string[]
     */
    private static function orphanLoops(): array {
        $mounts = is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '';
        $out = [];
        foreach (self::losetupLines() as $line) {
            if (strpos($line, 'unraid-aicliagents') === false) continue;
            if (strpos($line, '(deleted)') === false) continue;
            if (!preg_match('#\(([^()]*\.sqsh)\s*\(deleted\)\)#', $line, $m)) continue;
            // Loop device name is the part before the first ':' (e.g. /dev/loop13).
            $dev = trim((string)(explode(':', $line, 2)[0] ?? ''));
            if ($dev !== '' && self::loopIsMountSource($dev, $mounts)) {
                continue; // in-use deleted lower -> benign, not an orphan
            }
            $out[] = basename($m[1]);
        }
        return $out;
    }

    /**
     * Is $dev currently a mount SOURCE (field 1) in /proc/mounts? This plugin
     * mounts each layer .sqsh as a squashfs on a loop device at
     * /tmp/unraid-aicliagents/mnt/<layer>, and the live agent overlays reference
     * those squashfs MOUNTPOINTS (not /dev/loopN) as lowerdirs — so the loop
     * being a live mount source is exactly "this layer is in use by a live
     * overlay". Mirrors the bash ledger's _sl_collect_loop "mounted" decision.
     * EXACT-TOKEN on field 1 (NOT a substring): a bare strpos would false-match
     * /dev/loop12 inside /dev/loop124 and wrongly spare a genuine orphan.
     */
    private static function loopIsMountSource(string $dev, string $mounts): bool {
        if ($mounts === '' || $dev === '') return false;
        foreach (explode("\n", $mounts) as $row) {
            if ($row === '') continue;
            $src = strtok($row, " \t");   // first whitespace-delimited field
            if ($src === $dev) return true;
        }
        return false;
    }

    /** Count of plugin loop devices (live + deleted). */
    private static function pluginLoopCount(): int {
        $n = 0;
        foreach (self::losetupLines() as $line) {
            if (strpos($line, 'unraid-aicliagents') !== false) $n++;
        }
        return $n;
    }

    /** @return string[] raw `losetup -a` lines (test hook AICLI_SL_LOSETUP). */
    private static function losetupLines(): array {
        $fixture = getenv('AICLI_SL_LOSETUP');
        if ($fixture !== false && $fixture !== '' && is_readable($fixture)) {
            $raw = (string)@file_get_contents($fixture);
        } else {
            $raw = (string)@shell_exec('losetup -a 2>/dev/null');
        }
        return $raw === '' ? [] : array_filter(explode("\n", $raw), static fn($l) => trim($l) !== '');
    }
}
