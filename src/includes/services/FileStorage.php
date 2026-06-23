<?php
/**
 * <module_context>
 *     <name>FileStorage</name>
 *     <description>
 *         Epic #1310 — the single, authoritative filestorage management component.
 *         Consumers express WHAT they want (ensureReady / persist / release / status)
 *         and never touch files, mounts, layers or the manifest directly. All storage
 *         complexity (overlay, zram, bake, consolidate, reclaim, manifest, boot-
 *         integrity, locks, busy-arbitration, migration) is PRIVATE behind this
 *         interface (see docs/00-governance/adr/0001-single-filestorage-management-component.md).
 *
 *         STEP 1 (this commit) introduces the facade as a DARK 1:1 pass-through over
 *         the existing storagectl seam + StorageMountService — no consumer is
 *         re-pointed yet, so behaviour is unchanged. Later slices re-point consumers
 *         (Steps 3-5), add the passthrough backend (Step 6), move manifest authority
 *         into bash (Step 7), and bound migration to two triggers (Step 8).
 *     </description>
 *     <dependencies>storagectl.sh, StorageMountService, StoragePathResolver, TraceContext</dependencies>
 * </module_context>
 */

namespace AICliAgents\Services;

/**
 * Result of ensureReady(): is the entity's storage usable right now?
 * Preserves the `exit 2 = deferred` lingua franca — a deferred mount kept the
 * live overlay (the upper holds all data), so it is OK (usable), just deferred.
 */
final class FileStorageReadiness
{
    public bool $ok = false;
    public bool $deferred = false;
    public ?string $deferReason = null;
    public int $exit = 1;
    /** ready | deferred | unavailable */
    public string $state = 'unavailable';

    public static function fromExit(int $exit, ?string $deferReason): self
    {
        $r = new self();
        $r->exit = $exit;
        $r->deferReason = $deferReason;
        if ($exit === 0) {
            $r->ok = true; $r->deferred = false; $r->state = 'ready';
        } elseif ($exit === 2) {
            $r->ok = true; $r->deferred = true; $r->state = 'deferred';
        } else {
            $r->ok = false; $r->deferred = false; $r->state = 'unavailable';
        }
        return $r;
    }
}

/**
 * L1 (WP#1333): the {ok, deferred, deferReason, exit} shape + the exit-2-decode
 * (`exit 2 = deferred = still usable`) shared by persist() and release(), which were
 * byte-identical. ONE place to thread a future exit code (e.g. exit 3 guard_reject)
 * instead of duplicate decoders. `new self()` resolves to the using class.
 */
trait FileStorageExitResultTrait
{
    public bool $ok = false;
    public bool $deferred = false;
    public ?string $deferReason = null;
    public int $exit = 1;

    public static function fromExit(int $exit, ?string $deferReason): self
    {
        $r = new self();
        $r->exit = $exit;
        $r->deferReason = $deferReason;
        $r->ok = ($exit === 0 || $exit === 2);
        $r->deferred = ($exit === 2);
        return $r;
    }
}

/** Result of persist(): idempotent flush; self-defers (exit 2) when busy. */
final class FileStoragePersistResult
{
    use FileStorageExitResultTrait;
}

/** Result of release(): tear down for shutdown/close (subsumes unmount + flush). */
final class FileStorageReleaseResult
{
    use FileStorageExitResultTrait;
}

/** Result of migratePath()/migrateFormat() — lifecycle-bounded (Step 8). */
final class FileStorageMigrationResult
{
    public bool $ok = false;
    /** none | migrated | resumed | rolled_back | failed */
    public string $state = 'none';
    public ?string $error = null;

    public static function make(bool $ok, string $state, ?string $error = null): self
    {
        $r = new self();
        $r->ok = $ok; $r->state = $state; $r->error = $error;
        return $r;
    }
}

/**
 * status(entity) DTO — exactly the ADR shape, with additive fields so the
 * existing React UI keeps binding unchanged (toArray() is a SUPERSET of today's
 * aicli_get_storage_status). New UI reads supportsBake/supportsConsolidate to
 * render the Bake/Consolidate controls conditionally.
 */
final class FileStorageStatus
{
    /** ready | deferred | unavailable | halted | emergency | fresh */
    public string $state = 'unavailable';
    /** flash | passthrough — emitted by the seam (detect_backend). */
    public string $backend = 'flash';
    public bool $supportsBake = true;          // emitted by the seam
    public bool $supportsConsolidate = true;   // emitted by the seam
    public ?array $consolidate = null;         // emitted by the seam (home status)
    public ?array $layers = null;              // emitted by the seam; flash-only, null on passthrough
    // additive (UI superset) — emitted by the seam's mount{} object
    public string $merged = '';
    public bool $mounted = false;
    // L6 (WP#1333): the ADR's eventual fields — the storagectl seam (emit_json) does
    // NOT emit these TODAY, so they always carry their defaults. Kept as the forward
    // contract but NOT parsed from the seam (parsing absent keys only produced phantom
    // nulls and a dead state='halted' branch). TODO-with-emitter: emit haltState /
    // dirtyBytes / isDurable / forceReclaim / bootIntegrity from the seam, then wire
    // the derivation here. Until then HaltService/etc. remain the halt authority.
    public bool $isDurable = true;
    public int $dirtyBytes = 0;
    public ?array $haltState = null;
    public ?array $forceReclaim = null;
    public ?array $bootIntegrity = null;

    /** Back-compat string entry point — prefer fromArray() (no encode/decode round-trip). */
    public static function fromStoragectlJson(string $json): self
    {
        $j = json_decode($json, true);
        return self::fromArray(is_array($j) ? $j : []);
    }

    /**
     * L6 (WP#1333): build from the seam's already-decoded array (status() holds the
     * decoded array, so this kills the json_encode→json_decode round-trip). Parses
     * ONLY keys the seam actually emits; the ADR's not-yet-emitted fields keep their
     * defaults (see the property block).
     * @param array<string,mixed> $j
     */
    public static function fromArray(array $j): self
    {
        $s = new self();
        // L6 (WP#1333): parse ONLY the keys the seam (emit_json) actually emits today.
        $s->backend = (string)($j['backend'] ?? 'flash');
        $s->supportsBake = array_key_exists('supportsBake', $j) ? (bool)$j['supportsBake'] : ($s->backend === 'flash');
        $s->supportsConsolidate = array_key_exists('supportsConsolidate', $j) ? (bool)$j['supportsConsolidate'] : ($s->backend === 'flash');

        $mount = is_array($j['mount'] ?? null) ? $j['mount'] : [];
        $s->merged = (string)($mount['merged'] ?? '');
        $s->mounted = (bool)($mount['mounted'] ?? false);

        $s->layers = (isset($j['layers']) && is_array($j['layers'])) ? $j['layers'] : null;
        $s->consolidate = is_array($j['consolidate'] ?? null) ? $j['consolidate'] : null;

        // Coarse state from the emitted keys. NOTE: halt is NOT derivable here yet —
        // the seam does not emit haltState (see the property block); HaltService is the
        // halt authority until that emitter exists (TODO-with-emitter).
        if ($s->mounted) {
            $s->state = 'ready';
        } elseif (empty($s->layers)) {
            $s->state = 'fresh';
        } else {
            $s->state = 'unavailable';
        }
        return $s;
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'backend' => $this->backend,
            'isDurable' => $this->isDurable,
            'supportsBake' => $this->supportsBake,
            'supportsConsolidate' => $this->supportsConsolidate,
            'dirtyBytes' => $this->dirtyBytes,
            'mounted' => $this->mounted,
            'merged' => $this->merged,
            'layers' => $this->layers ?? [],
            'consolidate' => $this->consolidate,
            'haltState' => $this->haltState,
            'forceReclaim' => $this->forceReclaim,
            'bootIntegrity' => $this->bootIntegrity,
        ];
    }
}

final class FileStorage
{
    private const STORAGECTL = '/usr/local/emhttp/plugins/unraid-aicliagents/src/scripts/storage/storagectl.sh';

    /**
     * Parse an "type/id" entity string into ['type'=>..., 'id'=>...].
     * type ∈ {home, agent}; id is everything after the first '/'. Pure.
     * @return array{type:string,id:string}
     */
    public static function parseEntity(string $entity): array
    {
        $pos = strpos($entity, '/');
        if ($pos === false) {
            throw new \InvalidArgumentException("FileStorage entity must be 'type/id': '$entity'");
        }
        $type = substr($entity, 0, $pos);
        $id = substr($entity, $pos + 1);
        if ($type !== 'home' && $type !== 'agent') {
            throw new \InvalidArgumentException("FileStorage entity type must be home|agent: '$type'");
        }
        if ($id === '') {
            throw new \InvalidArgumentException("FileStorage entity id is empty: '$entity'");
        }
        return ['type' => $type, 'id' => $id];
    }

    /**
     * Backend capabilities for a persist PATH (genuine device test), via
     * detect_backend.sh. Returns ['backend','supportsBake','supportsConsolidate'].
     * Errs toward flash on any failure (data-safety invariant). Stable per path.
     * @return array{backend:string,supportsBake:bool,supportsConsolidate:bool}
     */
    public static function backendForPath(string $persistPath): array
    {
        // F9 (WP#1332): memoise per PHP request — the 5s UI poll resolves the same
        // path(s) every tick and the device verdict is boot-stable, so one shell_exec
        // per distinct path per request suffices (no per-entity re-probe).
        static $memo = [];
        if (array_key_exists($persistPath, $memo)) {
            return $memo[$persistPath];
        }
        $script = dirname(self::STORAGECTL) . '/detect_backend.sh';
        $backend = 'flash';
        if ($persistPath !== '' && is_file($script)) {
            // nosemgrep: php.lang.security.exec-use.exec-use
            $out = trim((string) @shell_exec('bash ' . escapeshellarg($script) . ' ' . escapeshellarg($persistPath) . ' 2>/dev/null'));
            if ($out === 'flash' || $out === 'passthrough') { $backend = $out; }
        }
        $flash = ($backend === 'flash');
        return $memo[$persistPath] = ['backend' => $backend, 'supportsBake' => $flash, 'supportsConsolidate' => $flash];
    }

    /**
     * S-01 (#1351, DARK phase): full capability probe for a persist PATH via the
     * read-only `storagectl probe` verb (detect_backend.sh probe_target). Returns
     * the decoded probe JSON: {schema, path, realpath, fstype, mount_class,
     * via_user_share, durability, wear, posix, rotational, max_file_bytes,
     * engine, upper_mode, refuse, warnings[], reasons[]}. Memoised per path per
     * request (same pattern as backendForPath: the 5s UI poll resolves the same
     * path(s) every tick and the verdict is boot-stable). On ANY failure (script
     * missing, non-zero exit, undecodable/implausible JSON) returns a safe
     * fallback — engine=layering, upper_mode=zram, refuse=false, reason
     * probe_failed — the err-toward-flash bias, and never blocks a caller.
     *
     * DARK invariant: nothing routes engine decisions through this yet;
     * backendForPath above stays the live verdict (do NOT derive one from the
     * other until the S-01 switch-over phase).
     *
     * AICLI_STORAGECTL env: test-only script-path override (mirrors the
     * AICLI_MANIFEST_PATH precedent in StoragePathResolver). Honoured by
     * probeTarget AND (since S-08 #1353) the seam() verbs, so PHPUnit can stub
     * status/mount round-trips; production never sets it.
     * @return array<string,mixed>
     */
    public static function probeTarget(string $path): array
    {
        static $memo = [];
        if (array_key_exists($path, $memo)) {
            return $memo[$path];
        }
        $fallback = [
            'schema' => 1, 'path' => $path, 'realpath' => $path,
            'fstype' => '', 'mount_class' => 'other', 'via_user_share' => false,
            'durability' => 'durable', 'wear' => 'wear_sensitive', 'posix' => 'posix_none',
            'rotational' => false, 'max_file_bytes' => 0,
            'engine' => 'layering', 'upper_mode' => 'zram', 'refuse' => false,
            'warnings' => [], 'reasons' => ['probe_failed'],
        ];
        $script = getenv('AICLI_STORAGECTL') ?: self::STORAGECTL;
        if ($path === '' || !is_file($script)) {
            return $memo[$path] = $fallback;
        }
        $out = [];
        $exit = 1;
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec('bash ' . escapeshellarg($script) . ' probe --persist ' . escapeshellarg($path) . ' 2>/dev/null', $out, $exit);
        $json = json_decode(implode("\n", $out), true);
        if ($exit !== 0 || !is_array($json)
            || !in_array($json['engine'] ?? '', ['layering', 'passthrough'], true)) {
            return $memo[$path] = $fallback;
        }
        return $memo[$path] = $json;
    }

    /**
     * F8 (WP#1332): the EFFECTIVE backend capabilities — the SINGLE PHP implementation
     * of the layers-stay-flash invariant, mirroring bash effective_backend_from_facts.
     * An entity that still has .sqsh layers is flash (bakeable) regardless of the
     * device verdict; only a layer-free entity adopts the device's backend. The UI
     * consumes THIS named method (not an inline re-implementation), so it can never
     * gate the Bake/Consolidate controls on a different rule than the engine.
     * backendForPath() above is the RAW DEVICE verdict (no invariant); this applies it.
     * @return array{backend:string,supportsBake:bool,supportsConsolidate:bool}
     */
    public static function effectiveBackendCaps(string $deviceBackend, bool $hasLayers): array
    {
        $eff   = $hasLayers ? 'flash' : ($deviceBackend === 'passthrough' ? 'passthrough' : 'flash');
        $flash = ($eff === 'flash');
        return ['backend' => $eff, 'supportsBake' => $flash, 'supportsConsolidate' => $flash];
    }

    /**
     * Bug #1380: the PURE graduate-OFFER rule — the PHP mirror of bash
     * graduate_offer_from_facts (detect_backend.sh). "Graduate" means MOVE THE
     * DATA OFF A USB FLASH DRIVE onto a durable non-array non-flash target, so
     * the offer is made IFF ALL of:
     *   - $deviceBackend == 'flash'          (the v1 GENUINE device test classes
     *                                         the persist device flash — a
     *                                         removable/USB-transport device)
     *   - $probeWear == 'wear_sensitive'     (the v2 probe confirms a genuine
     *                                         USB/removable device; an internal-
     *                                         boot ZFS/SSD is wear_normal and
     *                                         yields FALSE even WITH .sqsh layers)
     *   - $hasLayers                          (there is layered data on the stick)
     *   - $hasQualifyingTarget                (criterion b: a durable, non-array,
     *                                         non-flash, passthrough-capable,
     *                                         non-refused relocation target exists
     *                                         — see qualifyingGraduateTargets)
     * Conservative: any missing/odd fact refuses. On an internal-boot box every
     * entity is wear_normal, so this is FALSE for all of them (the Bug #1380
     * acceptance test). Consumed by StorageMetricsService (per-entity
     * `can_graduate`) and unit-tested without mounts (GraduateActionTest).
     *
     * NOTE — this REPLACES the S-10 (#1354) recommendation rule, which gated on
     * the in-place layering→passthrough op_graduate (device==passthrough &&
     * engine==passthrough). That op remains (storagectl graduate / op_graduate)
     * for the post-relocation engine drop, but is no longer the surfaced offer.
     */
    public static function canGraduate(string $deviceBackend, string $probeWear, bool $hasLayers, bool $hasQualifyingTarget): bool
    {
        return $deviceBackend === 'flash'
            && $probeWear === 'wear_sensitive'
            && $hasLayers
            && $hasQualifyingTarget;
    }

    /** Resolve the persist path for an entity (lazy-loads the resolver). */
    private static function persistPathFor(string $type, string $id): string
    {
        require_once __DIR__ . '/StoragePathResolver.php';
        return $type === 'home'
            ? (string) StoragePathResolver::homePersistPath($id)
            : (string) StoragePathResolver::agentPersistPath();
    }

    /** Run a storagectl verb for an entity; returns [exit, decodedJsonArray]. */
    private static function seam(string $verb, string $type, string $id, string $persist, array $extra = []): array
    {
        // S-08 (#1353): AICLI_STORAGECTL is the TEST-ONLY script-path override
        // (same hook probeTarget has used since S-01) so StorageJobLedgerTest can
        // stub the status fast-path without a live seam. Production never sets it.
        $script = getenv('AICLI_STORAGECTL') ?: self::STORAGECTL;
        // R-06: AICLI_TRACE_ID=<id> env prefix (TraceContext::shellPrefix —
        // empty when no request trace; id is format-validated, interpolation-safe)
        // so every storagectl verb's shell log lines join the AJAX trace.
        $cmd = TraceContext::shellPrefix()
            . 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($verb)
            . ' --type ' . escapeshellarg($type)
            . ' --id ' . escapeshellarg($id)
            . ' --persist ' . escapeshellarg($persist);
        foreach ($extra as $flag => $val) {
            $cmd .= ' ' . escapeshellarg($flag);
            if ($val !== null && $val !== '') { $cmd .= ' ' . escapeshellarg((string)$val); }
        }
        $out = [];
        $exit = 1;
        // nosemgrep: php.lang.security.exec-use.exec-use
        exec($cmd . ' 2>/dev/null', $out, $exit);
        $json = json_decode(implode("\n", $out), true);
        return [$exit, is_array($json) ? $json : []];
    }

    /**
     * ensureReady(entity): the entity's storage is mounted and writable.
     *
     * The mount path has irreducible PHP-resident concerns (the per-entity flock, the
     * Bug#1054 owner-fix, the overlay health-check, the migration gate), so — unlike
     * release/status — ensureReady keeps routing through the mount methods, which shell
     * the storagectl mount seam internally. L5 (WP#1333): it now surfaces the seam's
     * exit code (via the by-ref $exit) instead of collapsing exit 2 to a bool, so the
     * 'deferred' state its DTO models is reachable (a busy mount kept its live overlay —
     * usable AND deferred, not just "ready").
     */
    public static function ensureReady(string $entity, array $opts = []): FileStorageReadiness
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        require_once __DIR__ . '/StorageMountService.php';
        $exit = 1;
        $ok = ($type === 'home')
            ? StorageMountService::ensureHomeMounted($id, $exit)
            : StorageMountService::ensureAgentMounted($id, $exit);
        return FileStorageReadiness::fromExit(
            $ok ? $exit : 1,
            ($ok && $exit === 2) ? self::peekDeferReason($type, $id) : null
        );
    }

    /**
     * Unconditionally remount an agent overlay, bypassing the healthy-mount
     * fast-path in ensureReady/ensureAgentMounted. Used by
     * InstallerService::forceAgentRefresh (R3 verify-live gate) to swap a
     * stale lowerdir for the newest baked layer after a deferred refresh.
     *
     * Entity format: "agent/<id>" — same as ensureReady; home entities are
     * rejected (home overlays have no stale-lowerdir problem).
     *
     * Returns true when op_mount exits usably (exit 0 or 2).
     */
    public static function forceRemount(string $entity): bool
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        if ($type !== 'agent') {
            throw new \InvalidArgumentException("forceRemount only supports agent entities, got: '$type'");
        }
        require_once __DIR__ . '/StorageMountService.php';
        return StorageMountService::remountAgent($id);
    }

    /**
     * S-08 (#1353, docs/specs/STORAGE_ASYNC_JOBS.md): NON-BLOCKING readiness.
     *
     * Fast path: the entity's merged overlay is already mounted → {state:'ready'}
     * (one status seam call, no mount work). Otherwise a supervisor `mount` job
     * is enqueued (priority 5 — user-facing) and {state:'mounting', job_id} is
     * returned; the caller polls `storage_job_status` (or rides the activity
     * tray) and retries once the job lands. An ACTIVE mount job for the entity
     * is ridden instead of re-enqueued, so poll/retry loops can never pile jobs.
     *
     * Sync `ensureReady` stays for CLI/event/supervisor/auto-launch contexts
     * (headless callers with nobody to poll); if the enqueue itself fails
     * (queue helpers missing) this falls back to the sync path so a caller is
     * never left with neither a mount nor a job.
     *
     * @return array{state:string, job_id?:string, wait_s?:int, exit?:int, defer_reason?:?string}
     */
    public static function ensureReadyAsync(string $entity, array $opts = []): array
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);

        // Fast path — mounted now? (status() is read-only; ~one shell call.)
        if (self::status($entity)->mounted) {
            return ['state' => 'ready'];
        }

        require_once __DIR__ . '/SupervisorService.php';

        // Dedup: ride an existing active mount job for this entity.
        $existing = SupervisorService::findActiveJob($type, $id, 'mount');
        if ($existing !== null && !empty($existing['job_id'])) {
            return ['state' => 'mounting', 'job_id' => (string)$existing['job_id'], 'wait_s' => self::targetWaitS()];
        }

        // Backstop: make sure a supervisor is up to drain the job (cheap no-op
        // when running; best-effort — the queue entry is durable either way).
        SupervisorService::start();

        $jobId = SupervisorService::enqueueJob(
            $type, $id, 'mount', (string)($opts['reason'] ?? 'workspace_open'), 5
        );
        if ($jobId === null) {
            // Queue unavailable — degrade to the synchronous path rather than
            // stranding the caller with neither a mount nor a job.
            $r = self::ensureReady($entity, $opts);
            return [
                'state'        => $r->ok ? 'ready' : 'unavailable',
                'exit'         => $r->exit,
                'defer_reason' => $r->deferReason,
            ];
        }
        return ['state' => 'mounting', 'job_id' => $jobId, 'wait_s' => self::targetWaitS()];
    }

    /** S-08: deferred-mount wait budget (cfg storage_target_wait_s, default 300, min 30). */
    private static function targetWaitS(): int
    {
        require_once __DIR__ . '/ConfigService.php';
        $raw = ConfigService::getConfig()['storage_target_wait_s'] ?? '';
        $s = is_numeric($raw) ? (int)$raw : 300;
        return max(30, $s);
    }

    /**
     * persist(entity): flush in-RAM changes to durable storage (subsumes bake).
     *
     * L5 (WP#1333): persist's consumer-policy now lives in the FACADE (moved verbatim
     * from the deleted StorageMountService::commitChanges) and routes through the
     * storagectl seam directly — op_bake records the manifest under the entity lock
     * (F6), so persist sits at the same depth as release/status (no middle layer).
     */
    public static function persist(string $entity, array $opts = []): FileStoragePersistResult
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        require_once __DIR__ . '/StorageMountService.php';
        require_once __DIR__ . '/StoragePathResolver.php';
        require_once __DIR__ . '/LogService.php';
        require_once __DIR__ . '/LifecycleLogService.php';
        $persist = self::persistPathFor($type, $id);

        if ($type === 'agent') {
            // WP#748 J: agents always collapse to a single layer on install/upgrade. If
            // the consolidate DEFERS (overlay busy), fall back to a delta bake so the
            // ZRAM data is at least safe on Flash — without this all agent data lives
            // only in ZRAM and is lost on reboot. mapAgentCommitResult keeps the data-
            // safety contract (deferred + bake-ok must NOT be fatal) unit-tested.
            $deferred = false;
            $consolidated = StorageMountService::consolidate($type, $id, $deferred);
            $bakeRc = 0;
            if (!$consolidated && $deferred) {
                [$bakeRc, ] = self::seam('bake', $type, $id, $persist);
            }
            $exit = StorageMountService::mapAgentCommitResult($consolidated, $deferred, (int)$bakeRc);
            if ($exit === 1 && !$consolidated && $deferred) {
                LogService::log("Agent $id: consolidation deferred AND fallback delta bake failed (rc=$bakeRc) — data remains in ZRAM only.", LogService::LOG_ERROR, "FileStorage");
            } elseif ($exit === 2) {
                LogService::log("Agent $id: consolidation deferred; delta bake succeeded — data is safe on Flash, consolidation deferred to next install.", LogService::LOG_WARN, "FileStorage");
            }
            $reason = ($exit === 2) ? self::peekDeferReason($type, $id) : null;
            return FileStoragePersistResult::fromExit($exit, $reason);
        }

        // Home: flush straight through the seam.
        if (!StorageMountService::isPathAvailable($persist)) {
            LogService::log("Persist skipped for $type $id: storage path $persist not accessible.", LogService::LOG_WARN, "FileStorage");
            return FileStoragePersistResult::fromExit(1, null);
        }
        $upperDir = StoragePathResolver::zramUpper($type, $id);
        $dirtyMB = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $dirtyMB = (int)trim((string)$io);
        }
        LogService::log("Initiating SquashFS persistence bake for $type $id ($dirtyMB MB dirty)...", LogService::LOG_INFO, "FileStorage");
        LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'FileStorage', 'bake_start', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'persist_path' => $persist]);

        [$exit, ] = self::seam('bake', $type, $id, $persist);
        $reason = ($exit === 2) ? self::peekDeferReason($type, $id) : null;

        if ($exit === 0) {
            LogService::log("Successfully persisted $dirtyMB MB of RAM storage to Flash disk for $type $id.", LogService::LOG_INFO, "FileStorage");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'FileStorage', 'bake_ok', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'result' => 0]);
        } elseif ($exit === 2) {
            LogService::log("Backed up $dirtyMB MB to Flash for $id, RAM flush deferred (reason=" . ($reason ?? 'unknown') . ").", LogService::LOG_INFO, "FileStorage");
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'FileStorage', 'bake_deferred', ['type' => $type, 'id' => $id, 'dirty_mb' => $dirtyMB, 'reason' => $reason ?? 'unknown']);
        } else {
            LogService::log("FAILED SquashFS persistence bake for $type $id.", LogService::LOG_ERROR, "FileStorage");
            LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, 'FileStorage', 'bake_failed', ['type' => $type, 'id' => $id, 'result' => $exit]);
        }
        return FileStoragePersistResult::fromExit($exit, $reason);
    }

    /**
     * Read (without consuming) the defer-reason marker for an entity.
     *
     * S-03 (#1352): a marker older than the TTL (cfg defer_marker_ttl_h, default
     * 24 h) is STALE — its op either retried since or was abandoned — and is
     * silently ignored (the supervisor's reconcile reaps the file). Mirrors the
     * bash-side _defer_marker_fresh in common.sh; keep the two in sync.
     */
    private static function peekDeferReason(string $type, string $id): ?string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $marker = "/tmp/unraid-aicliagents/.bake_defer_reason_{$type}_{$safe}";
        if (is_file($marker)) {
            $mtime = @filemtime($marker);
            if ($mtime !== false && (time() - $mtime) >= self::deferMarkerTtlH() * 3600) {
                return null; // stale — ignore (reconcile will reap the file)
            }
            $raw = @file_get_contents($marker);
            if ($raw !== false) { $r = trim($raw); return $r !== '' ? $r : null; }
        }
        return null;
    }

    /** S-03 (#1352): defer-marker TTL in hours — cfg defer_marker_ttl_h, default 24, min 1. */
    private static function deferMarkerTtlH(): int
    {
        require_once __DIR__ . '/ConfigService.php';
        $config = ConfigService::getConfig();
        $raw = $config['defer_marker_ttl_h'] ?? '';
        $ttl = is_numeric($raw) ? (int)$raw : 24;
        return max(1, $ttl);
    }

    /** release(entity): tear down for shutdown/close (subsumes unmount + flush). */
    public static function release(string $entity, array $opts = []): FileStorageReleaseResult
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        $persist = self::persistPathFor($type, $id);
        // Epic #1310: the `release` verb (flush-then-unmount) — the central
        // manifest-under-lock bake path is the only writer.
        [$exit, $j] = self::seam('release', $type, $id, $persist);
        return FileStorageReleaseResult::fromExit($exit, $j['defer_reason'] ?? null);
    }

    /** status(entity): health / capabilities / dirty bytes for the UI + supervisor. */
    public static function status(string $entity): FileStorageStatus
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        $persist = self::persistPathFor($type, $id);
        [, $j] = self::seam('status', $type, $id, $persist);
        // L6 (WP#1333): $j is already the decoded seam array — build from it directly
        // (no json_encode→json_decode round-trip).
        return FileStorageStatus::fromArray(is_array($j) ? $j : []);
    }

    // ---- Migration: the TWO bounded triggers (Epic #1310 #1321/#1322) ---------
    // Migration is NEVER in the hot path. The component is TOLD to migrate at two
    // explicit moments; between them, the operate path assumes current-format,
    // current-path data.
    //
    // The in-progress marker SUPPRESSES the supervisor's path_drift discovery-halt
    // WHILE a migration runs (the config path moves ahead of the manifest mid-copy).
    // F4 (WP#1327): it is NOT a boot resume/rollback mechanism — there is no boot
    // consumer, and the marker lives on tmpfs (gone at reboot). The supervisor's check
    // is ts/mtime-bounded so a leaked marker (kill-mid-migration) cannot disable
    // drift protection for the rest of the uptime. The migration itself re-points the
    // manifest in-transaction (repointManifestPaths) so a COMPLETED migration leaves
    // no drift to discover; an INTERRUPTED one is re-discovered by the classifier at
    // next boot (the marker is gone), exactly as before the marker existed.

    private const MIGRATION_MARKER = '/tmp/unraid-aicliagents/.migration_inprogress.json';

    /**
     * Trigger 1 — persist/agent PATH change. The settings layer TELLS the
     * component "X → Y, migrate", so a normal boot never discovers a drifted path
     * to halt on (this is what makes the supervisor/boot path_drift discovery-halt
     * redundant). A crash-safe resumable marker is held for the WHOLE migration, so
     * an interrupted copy leaves an unambiguous marker for boot rather than tripping
     * normal ops.
     *
     * The real path-change consumer (StorageHandler::executeMigrate) owns a rich,
     * proven copy flow (per-file progress, agent+home, pre/post consolidation), so
     * it passes that work as $work and migratePath simply MARKER-BRACKETS it — it
     * does NOT replace the logic. With no $work, falls back to the proven
     * StorageMigrationService::migratePersistence rsync (the dark default).
     *
     * @param callable|null $work runs the actual migration under the marker;
     *        returns truthy on success. null → migratePersistence($new).
     */
    public static function migratePath(string $old, string $new, $work = null): FileStorageMigrationResult
    {
        self::writeMigrationMarker(['trigger' => 'path', 'from' => $old, 'to' => $new, 'phase' => 'running']);
        try {
            if (is_callable($work)) {
                $ok = (bool) $work();
            } else {
                require_once __DIR__ . '/StorageMigrationService.php';
                $ok = (bool) StorageMigrationService::migratePersistence($new);
            }
        } catch (\Throwable $e) {
            self::clearMigrationMarker();
            return FileStorageMigrationResult::make(false, 'failed', $e->getMessage());
        }
        self::clearMigrationMarker();
        return FileStorageMigrationResult::make($ok, $ok ? 'migrated' : 'failed');
    }

    /**
     * Is a format migration warranted? Pure gate for the "once per version bump"
     * trigger: any version change (including first install where $from is empty)
     * counts; a no-op re-save of the same version does NOT.
     */
    public static function formatMigrationNeeded(string $from, string $to): bool
    {
        return $from !== $to;
    }

    /**
     * Trigger 2 — plugin VERSION upgrade. The installer calls this ONCE on a
     * version bump to run any on-disk FORMAT migration (btrfs→squashfs,
     * geminicli→aicliagents, seq-keying). The scripts are idempotent + self-
     * detecting, so this is a thin, safe trigger; afterwards the data is
     * current-format. (NOTE: this does NOT convert flash→passthrough — that would
     * strand existing layered data; it's a separate, explicit, backed-up op.)
     */
    public static function migrateFormat(string $from, string $to): FileStorageMigrationResult
    {
        // "Once per version bump" gate — a same-version re-save is not a migration.
        if (!self::formatMigrationNeeded($from, $to)) {
            return FileStorageMigrationResult::make(true, 'none');
        }
        // This is the SINGLE explicit "version bumped → format-migration point" so a
        // normal boot never has to DISCOVER a format drift and halt.
        //
        // Deliberately NOT run synchronously here:
        //   • migrate-from-geminicli.sh — destructively tears down the *sibling*
        //     geminicli plugin (mv/rm -rf) and is a one-time legacy concern, not a
        //     per-bump migration. Resurrecting it on every upgrade (incl. production)
        //     would silently delete a co-installed geminicli plugin. Left dark.
        //   • migrate-btrfs-to-squashfs.sh — slow; stays BACKGROUNDED by the .plg
        //     installer (D-308 anti-hang) under its own migration.lock; running it
        //     sync would re-hang the installer.
        //
        // There is no safe synchronous per-bump format script today, so this trigger
        // is a no-op beyond the version gate. When one IS added, wrap its run in
        // writeMigrationMarker()/clearMigrationMarker() (mirroring migratePath) so an
        // interrupted format migration leaves a resumable .migration_inprogress.json.
        return FileStorageMigrationResult::make(true, 'none');
    }

    /** Uninstall: remove an entity's storage entirely (upper + layers + manifest). */
    public static function removeEntity(string $entity, array $opts = []): bool
    {
        ['type' => $type, 'id' => $id] = self::parseEntity($entity);
        $persist = self::persistPathFor($type, $id);
        [$exit, ] = self::seam('wipe', $type, $id, $persist);
        return $exit === 0;
    }

    /**
     * Bug #1379: permanently delete a HOME entity and ALL its residue.
     * Single authoritative deletion routine used by the AJAX handler AND
     * (via storagectl wipe) by the harness _purge_test_entity helper.
     *
     * Steps:
     *   1. Validate entity is type=home + id exists in manifest (or has .sqsh files)
     *   2. Refuse if the merge mount is in-use (fuser -sm → entity_in_use)
     *   3. storagectl wipe: unmount overlay, remove .sqsh layers, clear manifest
     *   4. Best-effort cleanup of residue: defer-reason marker, work dir, bake lock,
     *      commit/consolidate markers, halt markers
     *   5. Log lifecycle event home_entity_deleted
     *
     * @return array{ok:bool, reason?:string, message?:string}
     */
    public static function deleteHomeEntity(string $id): array
    {
        if ($id === '' || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $id)) {
            return ['ok' => false, 'reason' => 'invalid_id', 'message' => 'Invalid home id'];
        }

        $entity  = "home/$id";
        $persist = self::persistPathFor('home', $id);
        $safeId  = str_replace(['/', ' '], '_', $id);

        // Check if entity has any presence (manifest or .sqsh files on disk).
        require_once __DIR__ . '/LayerManifestService.php';
        $mentry     = LayerManifestService::getEntity($entity);
        $sqshGlob   = glob("$persist/home_{$id}_*.sqsh") ?: [];
        if ($mentry === null && empty($sqshGlob)) {
            return ['ok' => false, 'reason' => 'entity_not_found',
                    'message' => "No storage entity found for home/$id"];
        }

        // Refuse if merge mount is busy (a live terminal session is open).
        $mnt = "/tmp/unraid-aicliagents/work/{$id}/home";
        if (is_dir($mnt) && self::_mountIsBusy($mnt)) {
            return ['ok' => false, 'reason' => 'entity_in_use',
                    'message' => "Home $id has an active session — close all terminals for this user first."];
        }

        // Core deletion: storagectl wipe (umount + rm .sqsh + manifest remove).
        [$exit, ] = self::seam('wipe', 'home', $id, $persist);
        if ($exit !== 0) {
            return ['ok' => false, 'reason' => 'wipe_failed',
                    'message' => "storagectl wipe for home/$id exited $exit — check lifecycle log."];
        }

        // Best-effort residue cleanup (storagectl wipe may not remove all markers).
        @unlink("/tmp/unraid-aicliagents/.bake_defer_reason_home_{$safeId}");
        @unlink("/tmp/unraid-aicliagents/.bake_defer_reason_home_{$id}");
        @unlink("/tmp/unraid-aicliagents/.commit_marker_home_{$id}");
        @unlink("/tmp/unraid-aicliagents/.consolidate_marker_home_{$id}");
        @unlink("/var/run/aicli-bake-home-{$safeId}.lock");
        @unlink("/var/run/aicli-bake-home-{$id}.lock");
        // Work dir (contains merge mountpoint, upper, work subdirs when not in use).
        $workDir = "/tmp/unraid-aicliagents/work/{$id}";
        if (is_dir($workDir)) {
            // nosemgrep: php.lang.security.exec-use.exec-use — workDir derived from validated id
            @shell_exec('rm -rf ' . escapeshellarg($workDir) . ' 2>/dev/null');
        }
        // Halt markers for this entity.
        @unlink("/tmp/unraid-aicliagents/supervisor/halts/home/{$id}");
        @unlink("/tmp/unraid-aicliagents/supervisor/halts/home/{$id}.json");

        // Invalidate boot integrity cache so next fetch reflects the deletion.
        @unlink('/tmp/unraid-aicliagents/.boot_integrity_cache.json');

        // Lifecycle event.
        require_once __DIR__ . '/LifecycleLogService.php';
        LifecycleLogService::log(
            LifecycleLogService::LEVEL_INFO,
            'file_storage', 'home_entity_deleted',
            ['id' => $id, 'persist' => $persist]
        );

        return ['ok' => true, 'message' => "Home storage for '$id' deleted successfully."];
    }

    /**
     * Check whether a merge-mount directory has open file descriptors (fuser -sm).
     * Used by deleteHomeEntity() to refuse deletion of a live home.
     */
    private static function _mountIsBusy(string $mnt): bool
    {
        if (!is_dir($mnt)) return false;
        // mountpoint -q: is it actually mounted?
        // nosemgrep: php.lang.security.exec-use.exec-use — $mnt derived from validated id
        $isMounted = @shell_exec('mountpoint -q ' . escapeshellarg($mnt) . ' 2>/dev/null && echo 1');
        if (trim((string)$isMounted) !== '1') return false;
        // fuser -sm: are there open fds on the mount?
        // nosemgrep: php.lang.security.exec-use.exec-use — $mnt fully escaped
        $fuserOut = @shell_exec('fuser -sm ' . escapeshellarg($mnt) . ' 2>/dev/null && echo busy');
        return str_contains((string)$fuserOut, 'busy');
    }

    // ---- Manifest intent verbs (Epic #1310 Follow-on 2) ----------------------
    // Two niche manifest mutations consumers used to do directly. Routing them
    // through the facade keeps the manifest a PRIVATE storage-internal concern:
    // consumers express intent, never touch LayerManifestService. Both validate
    // the entity at the boundary, then delegate to the lock-guarded owner.

    /**
     * Drop the manifest entry for an ORPHAN/GHOST entity — an agent removed from
     * the registry, or an uninstalled agent whose lingering entry would otherwise
     * make the next boot-integrity sweep flag a phantom "missing" entity.
     * Idempotent (absent entry → no-op true).
     */
    public static function dropManifestEntry(string $entity): bool
    {
        self::parseEntity($entity); // validate at the facade boundary
        require_once __DIR__ . '/LayerManifestService.php';
        return LayerManifestService::removeEntity($entity);
    }

    /**
     * Point the manifest at a RESTORED layer set — e.g. a version-rollback that
     * swapped the on-disk layers back to a prior backup. Replaces the entity's
     * recorded layers + persistence path atomically under the manifest lock.
     *
     * @param array<int,array<string,mixed>> $layers
     */
    public static function pointManifestAtLayers(string $entity, array $layers, string $persistPath): bool
    {
        self::parseEntity($entity);
        require_once __DIR__ . '/LayerManifestService.php';
        return LayerManifestService::replaceLayers($entity, $layers, $persistPath);
    }

    /**
     * F3 (WP#1327): re-point the manifest's recorded persistence paths after a
     * COMPLETED path migration (old → new), scoped to one entity type ('home/' |
     * 'agent/'). Called from inside the migration marker bracket so a finished
     * migration leaves the manifest at the new path and a normal reconcile/boot never
     * DISCOVERS a stale path to false-halt as path_drift. Returns the count moved.
     */
    public static function repointManifestPaths(string $oldPrefix, string $newPrefix, string $entityPrefix = ''): int
    {
        require_once __DIR__ . '/LayerManifestService.php';
        return LayerManifestService::repointPathPrefix($oldPrefix, $newPrefix, $entityPrefix);
    }

    /**
     * Resolve the crash-safe marker path. AICLI_MIGRATION_MARKER overrides the
     * default (test hook — same pattern as AICLI_PROC_MOUNTS / AICLI_ITEST_BACKEND),
     * so PHPUnit can point it at a writable temp path box-independently.
     */
    private static function markerPath(): string
    {
        $env = getenv('AICLI_MIGRATION_MARKER');
        return ($env !== false && $env !== '') ? $env : self::MIGRATION_MARKER;
    }

    /** Is a migration currently in progress (crash-safe marker present)? */
    public static function isMigrationInProgress(): bool
    {
        return is_file(self::markerPath());
    }

    private static function writeMigrationMarker(array $plan): void
    {
        $marker = self::markerPath();
        @mkdir(dirname($marker), 0755, true);
        $plan['ts'] = gmdate('c');
        @file_put_contents($marker, json_encode($plan));
    }

    private static function clearMigrationMarker(): void
    {
        @unlink(self::markerPath());
    }
}
