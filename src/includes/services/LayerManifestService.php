<?php
/**
 * <module_context>
 *     <name>LayerManifestService</name>
 *     <description>Owns the durable layer manifest at /boot/config/plugins/unraid-aicliagents/layer_manifest.json. Single source of truth for which layers belong to which entity.</description>
 *     <dependencies>StoragePathResolver, LifecycleLogService</dependencies>
 *     <constraints>All mutating methods are flock-protected. Writes are atomic (tmp + fsync + rename). Never modifies manifests with schema_version > 1.</constraints>
 * </module_context>
 *
 * @internal Storage-component internal (Epic #1310). The layer manifest is a PRIVATE
 *           storage concern. Consumers must use the FileStorage facade intent verbs
 *           (dropManifestEntry / pointManifestAtLayers); the manifest mutators
 *           (addLayer / replaceLayers / removeEntity) are authored only by the
 *           component's own internals + bash bake/consolidate under the lock.
 *           Enforced by RegressionGuardsTest::testEpic1310ConsumersUseFacadeNotOwnerMethods.
 */

namespace AICliAgents\Services;

class LayerManifestService {
    public const SCHEMA_VERSION = 1;

    /** Lock file on tmpfs — cheap, no flash wear, released on crash (POSIX flock). */
    private const LOCK_PATH = '/var/run/aicli-supervisor.manifest.lock';

    /** Lock path override for isolated tests; production keeps the tmpfs default. */
    private static function lockPath(): string {
        $path = getenv('AICLI_MANIFEST_LOCK_PATH');
        return ($path !== false && $path !== '') ? $path : self::LOCK_PATH;
    }

    private const COMPONENT = 'LayerManifestService';

    // -----------------------------------------------------------------------
    // Read methods. S-04 (#1352): reads go through readManifest(), which takes a
    // best-effort shared lock + retries transient empty/torn reads (the tmp+rename
    // write is atomic on ext4; on vfat a transient zero-byte window exists on some
    // implementations — see readManifest()).
    // -----------------------------------------------------------------------

    /**
     * Returns the manifest entry for a single entity (e.g. 'home/root', 'agent/claude-code'),
     * or null if the entity is not present in the manifest.
     *
     * @return array<string, mixed>|null
     */
    public static function getEntity(string $entity): ?array {
        $manifest = self::readManifest();
        if ($manifest === null) {
            return null;
        }
        return $manifest['entities'][$entity] ?? null;
    }

    /**
     * Returns the full entities map from the manifest, or an empty array if unreadable.
     *
     * @return array<string, mixed>
     */
    public static function getAllEntities(): array {
        $manifest = self::readManifest();
        if ($manifest === null) {
            return [];
        }
        return $manifest['entities'] ?? [];
    }

    // -----------------------------------------------------------------------
    // Mutating methods (flock-protected, atomic write)
    // -----------------------------------------------------------------------

    /**
     * Appends a layer entry to an entity's expected_layers.
     * Also updates last_known_good_at and current_persistence_path.
     *
     * Required $layerEntry keys: filename, sha256, bytes, kind, created_at.
     * kind must be one of: consolidated, delta, recovered.
     *
     * @param array<string, mixed> $layerEntry
     */
    /**
     * #1315: Upsert a layer entry by filename instead of appending. Keyed on filename: an existing
     * entry for the same file is replaced (latest metadata wins) and any pre-existing duplicates of
     * that filename are collapsed to one; a new file is appended at the end. Entries with no
     * filename are passed through (cannot be keyed). Pure → unit-testable.
     *
     * @param array<int, array<string,mixed>> $existing
     * @param array<string, mixed> $layerEntry
     * @return array<int, array<string,mixed>>
     */
    public static function upsertLayer(array $existing, array $layerEntry): array {
        $fn = (string)($layerEntry['filename'] ?? '');
        $out = [];
        $replaced = false;
        foreach ($existing as $e) {
            if ($fn !== '' && (string)($e['filename'] ?? '') === $fn) {
                if (!$replaced) {
                    $out[]    = $layerEntry; // first match -> replace in place
                    $replaced = true;
                }
                // any further entries with this filename are duplicates -> drop
                continue;
            }
            $out[] = $e;
        }
        if (!$replaced) {
            $out[] = $layerEntry;
        }
        // $out is built solely via $out[] above, so it is already a 0-indexed list.
        return $out;
    }

    /**
     * @param bool $plain true = add exactly as given. A layer of kind 'recovered' is
     *                    normally routed through BootIntegrityService::addRecoveredLayer
     *                    first (R7: it may replace an older rolling busy snapshot).
     *                    That method calls back here with $plain = true.
     */
    public static function addLayer(string $entity, array $layerEntry, bool $plain = false): bool {
        if (!$plain && ($layerEntry['kind'] ?? '') === 'recovered'
            && class_exists(__NAMESPACE__ . '\\BootIntegrityService')) {
            try {
                return BootIntegrityService::addRecoveredLayer($entity, $layerEntry);
            } catch (\Throwable $e) {
                // Never fail on the new rule: fall back to the plain add below.
            }
        }
        return self::withLock(function () use ($entity, $layerEntry) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                $manifest['entities'][$entity] = [
                    'expected_layers'          => [],
                    'last_known_good_at'       => null,
                    'current_persistence_path' => null,
                ];
            }

            // #1315: upsert by filename (NOT blind append) so re-baking/re-recording the same
            // layer doesn't bloat the manifest with duplicates, and any pre-existing duplicates of
            // this filename collapse to one. Duplicates inflate expected_count and can mask a real
            // missing-layer count in boot-integrity.
            $manifest['entities'][$entity]['expected_layers'] = self::upsertLayer(
                $manifest['entities'][$entity]['expected_layers'] ?? [],
                $layerEntry
            );
            $manifest['entities'][$entity]['last_known_good_at'] = self::now();

            // Update persistence path if provided in the layer entry
            if (isset($layerEntry['persist_path'])) {
                $manifest['entities'][$entity]['current_persistence_path'] = $layerEntry['persist_path'];
            }

            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'layer_added',
                    ['entity' => $entity, 'filename' => $layerEntry['filename'] ?? '?', 'kind' => $layerEntry['kind'] ?? '?']
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'layer_add_failed',
                    ['entity' => $entity, 'filename' => $layerEntry['filename'] ?? '?']
                );
            }
            return $ok;
        });
    }

    /**
     * R7: in ONE locked atomic write, record $newEntry and drop the layer named
     * $oldFilename. Returns false (and writes nothing) when the entity is absent,
     * when $oldFilename is not in the manifest, or when both names are the same.
     * The caller deletes the old layer FILE only after this returns true.
     *
     * @param array<string, mixed> $newEntry
     */
    public static function replaceLayer(string $entity, array $newEntry, string $oldFilename): bool {
        $newName = (string)($newEntry['filename'] ?? '');
        if ($newName === '' || $oldFilename === '' || $newName === $oldFilename) {
            return false;
        }
        return self::withLock(function () use ($entity, $newEntry, $newName, $oldFilename) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }
            $layers = $manifest['entities'][$entity]['expected_layers'] ?? null;
            if (!is_array($layers)) {
                return false;
            }
            $found = false;
            foreach ($layers as $l) {
                if ((string)($l['filename'] ?? '') === $oldFilename) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
            $kept = array_values(array_filter(
                $layers,
                static fn($l) => (string)($l['filename'] ?? '') !== $oldFilename
            ));
            $manifest['entities'][$entity]['expected_layers'] = self::upsertLayer($kept, $newEntry);
            $manifest['entities'][$entity]['last_known_good_at'] = self::now();
            if (isset($newEntry['persist_path'])) {
                $manifest['entities'][$entity]['current_persistence_path'] = $newEntry['persist_path'];
            }
            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            LifecycleLogService::log(
                $ok ? LifecycleLogService::LEVEL_INFO : LifecycleLogService::LEVEL_ERROR,
                self::COMPONENT,
                $ok ? 'layer_replaced' : 'layer_replace_failed',
                ['entity' => $entity, 'filename' => $newName, 'dropped' => $oldFilename]
            );
            return $ok;
        });
    }

    /**
     * Removes a layer entry by filename from an entity.
     */
    public static function removeLayer(string $entity, string $filename): bool {
        return self::withLock(function () use ($entity, $filename) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                return true; // nothing to remove
            }

            $before = count($manifest['entities'][$entity]['expected_layers'] ?? []);
            $manifest['entities'][$entity]['expected_layers'] = array_values(
                array_filter(
                    $manifest['entities'][$entity]['expected_layers'] ?? [],
                    static fn($layer) => ($layer['filename'] ?? '') !== $filename
                )
            );
            $after = count($manifest['entities'][$entity]['expected_layers']);

            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'layer_removed',
                    ['entity' => $entity, 'filename' => $filename, 'removed' => ($before - $after)]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'layer_remove_failed',
                    ['entity' => $entity, 'filename' => $filename]
                );
            }
            return $ok;
        });
    }

    /**
     * Replaces the entire expected_layers array for an entity (used by consolidate).
     * Also updates current_persistence_path.
     *
     * @param array<int, array<string, mixed>> $layerEntries
     */
    public static function replaceLayers(string $entity, array $layerEntries, string $persistencePath): bool {
        return self::withLock(function () use ($entity, $layerEntries, $persistencePath) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                $manifest['entities'][$entity] = [];
            }

            $manifest['entities'][$entity]['expected_layers']          = array_values($layerEntries);
            $manifest['entities'][$entity]['last_known_good_at']       = self::now();
            $manifest['entities'][$entity]['current_persistence_path'] = $persistencePath;
            $manifest['updated_at']                                     = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'layers_replaced',
                    ['entity' => $entity, 'count' => count($layerEntries), 'path' => $persistencePath]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'layers_replace_failed',
                    ['entity' => $entity]
                );
            }
            return $ok;
        });
    }

    /**
     * F3 (WP#1327): PURE — re-point a single persistence path from $oldPrefix to
     * $newPrefix, matched on a path boundary (exact, or a "$oldPrefix/" sub-path).
     * Returns $current UNCHANGED when it is not under $oldPrefix. Pure so the prefix
     * logic is unit-testable in the hermetic container (the I/O round-trip needs a
     * writable /boot and is skipped there).
     */
    public static function repointPath(string $current, string $oldPrefix, string $newPrefix): string {
        $oldN = rtrim($oldPrefix, '/');
        $newN = rtrim($newPrefix, '/');
        if ($oldN === '' || $current === '') {
            return $current;
        }
        if ($current === $oldN) {
            return $newN;
        }
        if (strncmp($current, $oldN . '/', strlen($oldN) + 1) === 0) {
            return $newN . substr($current, strlen($oldN));
        }
        return $current;
    }

    /**
     * F3 (WP#1327): re-point every entity's current_persistence_path from $oldPrefix
     * to $newPrefix, IN-TRANSACTION under the manifest lock. Called from the migration
     * marker bracket so a completed path migration leaves the manifest at the NEW path
     * — otherwise the supervisor/boot DISCOVERS a stale path and false-halts path_drift
     * (executeMigrate previously left re-pointing to future bakes/consolidates, which
     * only fire for >1-layer entities). $entityPrefix scopes the change to one entity
     * type ('home/' or 'agent/') so a shared old base with diverging new paths can't
     * cross-point. Returns the number of entities moved (0 on no-op or failure).
     */
    public static function repointPathPrefix(string $oldPrefix, string $newPrefix, string $entityPrefix = ''): int {
        $moved = 0;
        self::withLock(function () use ($oldPrefix, $newPrefix, $entityPrefix, &$moved) {
            // R-045 (#623): loadForWrite keeps a copy of a damaged live file as
            // .corrupt and restores, rebuilds or refuses. readManifest would have
            // let the write below replace the damaged file with the older .bak
            // and keep no evidence.
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'paths_repoint_failed',
                    ['from' => $oldPrefix, 'to' => $newPrefix, 'reason' => 'manifest_unusable']
                );
                return false;
            }
            $count = 0;
            // By reference over the real array: a reference loop over a "?? []" copy
            // counts the change and then loses it.
            if (!isset($manifest['entities']) || !is_array($manifest['entities'])) {
                return true;
            }
            foreach ($manifest['entities'] as $entity => &$e) {
                if ($entityPrefix !== '' && strncmp((string)$entity, $entityPrefix, strlen($entityPrefix)) !== 0) {
                    continue;
                }
                $cur = $e['current_persistence_path'] ?? '';
                if ($cur === '') {
                    continue;
                }
                $new = self::repointPath((string)$cur, $oldPrefix, $newPrefix);
                if ($new !== $cur) {
                    $e['current_persistence_path'] = $new;
                    $count++;
                }
            }
            unset($e);
            if ($count === 0) {
                return true; // nothing under the old prefix — clean no-op
            }
            $manifest['updated_at'] = self::now();
            $ok = self::atomicWrite($manifest);
            if ($ok) {
                $moved = $count;
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'paths_repointed',
                    ['from' => $oldPrefix, 'to' => $newPrefix, 'entity_prefix' => $entityPrefix, 'count' => $count]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'paths_repoint_failed',
                    ['from' => $oldPrefix, 'to' => $newPrefix]
                );
            }
            return $ok;
        });
        return $moved;
    }

    /**
     * Marks a specific layer as corrupt without removing it.
     * Adds a 'corrupt' sub-key to the layer entry: {reason, marked_at}.
     */
    public static function markCorrupt(string $entity, string $filename, string $reason): bool {
        return self::withLock(function () use ($entity, $filename, $reason) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                return false;
            }

            $found = false;
            foreach ($manifest['entities'][$entity]['expected_layers'] as &$layer) {
                if (($layer['filename'] ?? '') === $filename) {
                    $layer['corrupt'] = [
                        'reason'    => $reason,
                        'marked_at' => self::now(),
                    ];
                    $found = true;
                    break;
                }
            }
            unset($layer);

            if (!$found) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_WARN,
                    self::COMPONENT,
                    'mark_corrupt_not_found',
                    ['entity' => $entity, 'filename' => $filename]
                );
                return false;
            }

            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_WARN,
                    self::COMPONENT,
                    'layer_marked_corrupt',
                    ['entity' => $entity, 'filename' => $filename, 'reason' => $reason]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'layer_mark_corrupt_failed',
                    ['entity' => $entity, 'filename' => $filename]
                );
            }
            return $ok;
        });
    }

    /**
     * S-10 (#1354): record the entity's storage backend (additive per-entity
     * "backend" field — "flash" | "passthrough"). Setting PASSTHROUGH also clears
     * the entity's expected_layers in the SAME locked atomic write: this is the
     * graduate migration's single authority flip (layers were already moved to
     * .graduated/ under the write-ahead intent; after this write the plain
     * passthrough dir is authoritative and the bash classifier's
     * _entity_manifest_expects_layers un-pins the entity). Setting FLASH only
     * writes the field (the manual rollback path; reconcile re-records the
     * restored layers as untracked→recovered). Creates the entity entry if
     * absent (idempotent).
     */
    public static function setBackend(string $entity, string $backend): bool {
        if (!in_array($backend, ['flash', 'passthrough'], true)) {
            return false;
        }
        return self::withLock(function () use ($entity, $backend) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                $manifest['entities'][$entity] = [
                    'expected_layers'          => [],
                    'last_known_good_at'       => null,
                    'current_persistence_path' => null,
                ];
            }
            $manifest['entities'][$entity]['backend'] = $backend;
            if ($backend === 'passthrough') {
                // ONE locked write: backend flip + expected-layers clear together,
                // so no reader can ever observe passthrough-with-expected-layers.
                $manifest['entities'][$entity]['expected_layers'] = [];
            }
            $manifest['entities'][$entity]['last_known_good_at'] = self::now();
            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            LifecycleLogService::log(
                $ok ? LifecycleLogService::LEVEL_INFO : LifecycleLogService::LEVEL_ERROR,
                self::COMPONENT,
                $ok ? 'backend_set' : 'backend_set_failed',
                ['entity' => $entity, 'backend' => $backend]
            );
            return $ok;
        });
    }

    /**
     * Removes an entity and all its layer entries from the manifest.
     * Returns true if the entity was present and removed, or if it was already absent.
     */
    public static function removeEntity(string $entity): bool {
        return self::withLock(function () use ($entity) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            if (!isset($manifest['entities'][$entity])) {
                return true; // already absent — idempotent
            }

            unset($manifest['entities'][$entity]);
            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'entity_removed',
                    ['entity' => $entity]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'entity_remove_failed',
                    ['entity' => $entity]
                );
            }
            return $ok;
        });
    }

    /**
     * Removes all entities whose key matches the given regex pattern.
     * Returns the count of entities removed.
     */
    public static function removeEntitiesMatching(string $pattern): int {
        $removed = 0;
        self::withLock(function () use ($pattern, &$removed) {
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }

            $before = array_keys($manifest['entities'] ?? []);
            $pruned = [];
            foreach ($before as $key) {
                if (preg_match($pattern, $key)) {
                    unset($manifest['entities'][$key]);
                    $pruned[] = $key;
                }
            }

            if (empty($pruned)) {
                return true;
            }

            $removed = count($pruned);
            $manifest['updated_at'] = self::now();

            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'phantom_smoke_state_pruned',
                    ['count' => $removed, 'entities' => $pruned]
                );
            } else {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'phantom_prune_failed',
                    ['attempted' => $pruned]
                );
                $removed = 0;
            }
            return $ok;
        });
        return $removed;
    }

    /**
     * Initializes an empty manifest at the canonical path if none exists.
     * Idempotent: does nothing if the manifest already exists.
     */
    public static function initEmpty(): bool {
        $path = StoragePathResolver::manifestPath();
        if (file_exists($path)) {
            return true;
        }

        return self::withLock(function () use ($path) {
            // Re-check inside the lock — another process may have written it
            if (file_exists($path)) {
                return true;
            }
            $manifest = self::emptyManifest();
            $ok = self::atomicWrite($manifest);
            if ($ok) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_INFO,
                    self::COMPONENT,
                    'manifest_initialized',
                    ['path' => $path]
                );
            }
            return $ok;
        });
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    /**
     * Computes the hex sha256 of a file, or returns null on read failure.
     */
    public static function computeFileSha256(string $path): ?string {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $hash = @hash_file('sha256', $path);
        return ($hash !== false) ? $hash : null;
    }

    /**
     * Classify a layer .sqsh from its filename: a baked-down full layer carries
     * the "_consolidated_" marker in its name, every other layer is an append
     * delta. Centralised here so callers that rebuild manifest entries (restore,
     * recovery) do not each hand-roll the same string check.
     */
    public static function classifyLayerKind(string $filename): string {
        return (strpos($filename, '_consolidated_') !== false) ? 'consolidated' : 'delta';
    }

    /**
     * #338: the sort key of a layer name — "<seq10>_<dt>" for the current
     * grammar ({type}_{id}_{kind}_{seq10}_{dt}.sqsh), so a byte-order sort is
     * chronological whatever the kind; "0000000000_<name>" (below every current
     * name) for any older grammar. Mirrors bash common.sh _layer_sort_key.
     * A plain sort of whole names is NOT chronological: "_consolidated_" sorts
     * before "_delta_", so a newer base would sort below an older delta.
     */
    public static function layerSortKey(string $filename): string {
        $bn = basename($filename);
        if (preg_match('/_(?:delta|consolidated)_(\d{10})_(\d{8}T\d{6}Z)\.sqsh$/', $bn, $m)) {
            return $m[1] . '_' . $m[2];
        }
        return '0000000000_' . $bn;
    }

    /**
     * #338 (docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "2026-09-26 — #338 agent
     * layer retention"): the layers ONE agent mount stacks, newest first — the
     * newest layer and each older one down to and including the newest base
     * (a "_consolidated_" layer holds the complete agent tree). A layer below
     * that base is not part of the view. No base → every layer. PHP mirror of
     * bash common.sh _layer_stack_cut; keep the two in step.
     *
     * @param array<int,string> $names layer basenames or paths, any order
     * @return array<int,string> the stacked entries (as given), newest first
     */
    public static function agentStack(array $names): array {
        usort($names, static fn($a, $b) => strcmp(self::layerSortKey((string)$b), self::layerSortKey((string)$a)));
        $stack = [];
        foreach ($names as $n) {
            $stack[] = $n;
            if (self::classifyLayerKind(basename((string)$n)) === 'consolidated') {
                break;
            }
        }
        return $stack;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Reads and JSON-decodes the manifest. Returns null on missing or decode error.
     *
     * S-04 (#1352): torn-read hardening against the bash-side writer (manifest_write.sh
     * runs atomicWrite under LOCK_EX via php -r one-shots). The tmp+rename write is
     * atomic on ext4, but on vfat (/boot) some implementations expose a transient
     * zero-byte window — a single unretried read could decode to null and be treated
     * as "empty manifest", mis-classifying healthy entities (false total_loss).
     * Mitigation: a best-effort SHARED lock on the writer's lock file (non-blocking,
     * so a reader inside withLock's LOCK_EX can never self-deadlock) + a 3×50 ms
     * retry on empty-read / decode failure.
     *
     * @return array<string, mixed>|null
     */
    private static function readManifest(): ?array {
        $state = self::readManifestState();
        if ($state['state'] === 'ok') {
            return $state['data'];
        }
        // #569: a missing or unparsable file falls back to the previous good copy
        // (read-only: the bad file is not touched here).
        return self::parseFile(self::bakPath());
    }

    /** #569: path of the previous good generation, kept at each atomic rename. */
    public static function bakPath(): string {
        return StoragePathResolver::manifestPath() . '.bak';
    }

    /**
     * #569: decode one manifest file without retry. Null when it is missing, empty
     * or not valid JSON for an array.
     *
     * @return array<string, mixed>|null
     */
    private static function parseFile(string $path): ?array {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * #569: read the live manifest and say what state it is in.
     * state is 'ok' (data is the manifest), 'missing' (no file) or 'unparsable'
     * (a file that is empty or not valid JSON after the retries).
     *
     * @return array{state: string, data: array<string, mixed>|null}
     */
    private static function readManifestState(): array {
        $path = StoragePathResolver::manifestPath();
        if (!file_exists($path)) {
            return ['state' => 'missing', 'data' => null];
        }
        // Best-effort LOCK_SH (belt-and-braces vs the bash writer's LOCK_EX).
        // MUST be non-blocking: readManifest is also called from inside withLock's
        // LOCK_EX (same process, different fd — flock treats those as conflicting),
        // so a blocking LOCK_SH here would self-deadlock every mutator.
        $lockFd   = @fopen(self::lockPath(), 'c');
        $haveLock = ($lockFd !== false) && @flock($lockFd, LOCK_SH | LOCK_NB);
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($attempt > 0) {
                    usleep(50000); // 50 ms between retries
                }
                $raw = @file_get_contents($path);
                if ($raw === false || $raw === '') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return ['state' => 'ok', 'data' => $decoded];
                }
            }
            return ['state' => 'unparsable', 'data' => null];
        } finally {
            if ($haveLock) {
                @flock($lockFd, LOCK_UN);
            }
            if ($lockFd !== false) {
                @fclose($lockFd);
            }
        }
    }

    /**
     * #569: load the manifest for a mutator, inside the lock. NEVER hands a mutator
     * an empty manifest in place of a damaged one.
     *   - good file                  -> that manifest
     *   - missing file               -> the .bak, else a rebuild from the layers on
     *                                   disk, else a new empty manifest (a first run)
     *   - empty / unparsable file    -> a copy of the bad file is kept as .corrupt,
     *                                   then the .bak, else a rebuild from the layers
     *                                   on disk, else null: the write is REFUSED so the
     *                                   next save cannot replace the file with a
     *                                   one-entity manifest.
     * Null means "refuse and log"; every mutator returns false on null.
     *
     * @return array<string, mixed>|null
     */
    private static function loadForWrite(): ?array {
        $st = self::readManifestState();
        if ($st['state'] === 'ok') {
            return $st['data'];
        }
        $path = StoragePathResolver::manifestPath();
        if ($st['state'] === 'unparsable') {
            $keep = $path . '.corrupt';
            if (@copy($path, $keep)) {
                LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, self::COMPONENT,
                    'manifest_unparsable', ['path' => $path, 'kept_copy' => $keep]);
            } else {
                LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, self::COMPONENT,
                    'manifest_unparsable', ['path' => $path, 'kept_copy' => '']);
            }
        }
        $bak = self::parseFile(self::bakPath());
        if ($bak !== null) {
            LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, self::COMPONENT,
                'manifest_restored_from_bak', ['was' => $st['state'], 'entities' => count($bak['entities'] ?? [])]);
            return $bak;
        }
        $entities = self::rebuildEntitiesFromDisk();
        if ($entities !== []) {
            $m = self::emptyManifest();
            $m['entities'] = $entities;
            LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, self::COMPONENT,
                'manifest_rebuilt_from_layers', ['was' => $st['state'], 'entities' => array_keys($entities)]);
            return $m;
        }
        if ($st['state'] === 'missing') {
            return self::emptyManifest();
        }
        LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, self::COMPONENT,
            'manifest_write_refused', ['reason' => 'unparsable_no_bak_no_layers', 'path' => $path]);
        return null;
    }

    // -----------------------------------------------------------------------
    // #569 / #568: rebuild manifest entries from the layer files on disk
    // -----------------------------------------------------------------------

    /** Smoke-test entity ids are never rebuilt (see supervisor cleanup-phantoms). */
    private static function isSmokeId(string $id): bool {
        return preg_match('/^smoke[a-z]*[0-9]*$/D', $id) === 1;
    }

    /**
     * Split a layer file name {type}_{id}_{kind}_{seq10}_{dt}.sqsh into its parts.
     * Null for any name that does not follow the current grammar.
     *
     * @return array{type: string, id: string, kind: string}|null
     */
    public static function parseLayerName(string $filename): ?array {
        if (preg_match('/^(home|agent)_(.+)_(delta|consolidated)_\d{10}_\d{8}T\d{6}Z\.sqsh$/D', basename($filename), $m) !== 1) {
            return null;
        }
        return ['type' => $m[1], 'id' => $m[2], 'kind' => $m[3]];
    }

    /**
     * R-002 (#580): is $filename a layer of EXACTLY this entity? A prefix test on
     * "{type}_{id}_" is wrong: home "bob" would own the layers of home "bob_smith".
     * The text after the prefix must be a whole layer-name tail, current or legacy.
     * Mirrors layer_name_is_of() in src/scripts/storage/detect_backend.sh.
     */
    public static function isLayerOfEntity(string $filename, string $type, string $id): bool {
        $name = basename($filename);
        if ($type === '' || $id === '' || substr($name, -5) !== '.sqsh') {
            return false;
        }
        $prefix = $type . '_' . $id . '_';
        $name   = substr($name, 0, -5);
        if (strncmp($name, $prefix, strlen($prefix)) !== 0) {
            return false;
        }
        $rest = substr($name, strlen($prefix));
        return preg_match('/^(?:(?:delta|consolidated)_\d{10}_\d{8}T\d{6}Z|(?:delta|consolidated)_\d{8}T\d{6}Z|delta_\d+|(?:v\d+_)?vol\d+)$/D', $rest) === 1;
    }

    /**
     * The layer files of exactly this entity in $dir (full paths, name order).
     *
     * @return array<int, string>
     */
    public static function layerFilesOfEntity(string $dir, string $type, string $id): array {
        $out = [];
        foreach ((@glob(rtrim($dir, '/') . "/{$type}_{$id}_*.sqsh") ?: []) as $f) {
            if (self::isLayerOfEntity($f, $type, $id)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * True when the file looks like a complete squashfs: not empty, starts with
     * the magic 'hsqs', and the size the header claims (bytes_used, offset 40)
     * is above zero and not larger than the file. A layer that is still being
     * written, or was cut short, has no valid bytes_used.
     */
    public static function looksLikeCompleteSquashfs(string $path): bool {
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        $size = (int)@filesize($path);
        if ($size < 96) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = @fread($fh, 48);
        @fclose($fh);
        if (!is_string($head) || strlen($head) < 48 || substr($head, 0, 4) !== 'hsqs') {
            return false;
        }
        $u = @unpack('Pused', substr($head, 40, 8));
        $used = is_array($u) ? (int)$u['used'] : 0;
        return $used > 0 && $used <= $size;
    }

    /**
     * The manifest entries of one entity, from the layer files in $dir, oldest first.
     * A zero-byte file and a file without a complete squashfs header are not layers
     * and are left out. Nothing is changed on disk.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function layerEntriesFromDir(string $dir, string $type, string $id): array {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach ((@scandir($dir) ?: []) as $n) {
            $p = self::parseLayerName((string)$n);
            if ($p !== null && $p['type'] === $type && $p['id'] === $id && is_file("$dir/$n")) {
                $names[] = (string)$n;
            }
        }
        usort($names, static fn($a, $b) => strcmp(self::layerSortKey($a), self::layerSortKey($b)));
        $out = [];
        foreach ($names as $n) {
            $full  = rtrim($dir, '/') . '/' . $n;
            $bytes = (int)@filesize($full);
            if ($bytes <= 0) {
                continue;
            }
            // R-044 (#622): a layer cut short by a power loss has a size above zero
            // but no complete squashfs header. Hashing it would record the damaged
            // bytes as a healthy layer, so it is left out and logged.
            if (!self::looksLikeCompleteSquashfs($full)) {
                LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, self::COMPONENT,
                    'rebuild_skipped_incomplete_layer', ['file' => $n, 'bytes' => $bytes]);
                continue;
            }
            $out[] = [
                'filename'     => $n,
                'sha256'       => self::computeFileSha256($full) ?? '',
                'bytes'        => $bytes,
                'kind'         => self::classifyLayerKind($n),
                'created_at'   => gmdate('Y-m-d\TH:i:s\Z', (int)@filemtime($full)),
                'persist_path' => rtrim($dir, '/'),
            ];
        }
        return $out;
    }

    /**
     * The folders that hold layers: [type => dir]. Home layers sit in the home
     * persist folder, agent layers in the agent persist folder.
     *
     * @return array<string, string>
     */
    private static function layerDirs(): array {
        // Test-only hook (same idea as AICLI_MANIFEST_PATH): point both folders at
        // temp folders so a unit test never reads the real persist folders.
        $eh = (string)getenv('AICLI_LAYER_DIR_HOME');
        $ea = (string)getenv('AICLI_LAYER_DIR_AGENT');
        if ($eh !== '' || $ea !== '') {
            return ['home' => $eh, 'agent' => $ea];
        }
        $cfg  = ConfigService::getConfig();
        $user = (string)($cfg['user'] ?? '');
        if ($user === '') {
            $user = 'root';
        }
        return [
            'home'  => StoragePathResolver::homePersistPath($user),
            'agent' => StoragePathResolver::agentPersistPath(),
        ];
    }

    /**
     * Entity ids (per type) that have at least one layer file in the layer folders.
     *
     * @return array<string, array<int, string>> ['home' => [ids], 'agent' => [ids]]
     */
    private static function idsOnDisk(): array {
        $found = ['home' => [], 'agent' => []];
        foreach (self::layerDirs() as $type => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((@scandir($dir) ?: []) as $n) {
                $p = self::parseLayerName((string)$n);
                if ($p !== null && $p['type'] === $type && !self::isSmokeId($p['id'])) {
                    $found[$type][$p['id']] = true;
                }
            }
        }
        return ['home' => array_keys($found['home']), 'agent' => array_keys($found['agent'])];
    }

    /**
     * #569: a whole entities map rebuilt from the layers on disk (no manifest, no .bak).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rebuildEntitiesFromDisk(): array {
        $dirs = self::layerDirs();
        $out  = [];
        foreach (self::idsOnDisk() as $type => $ids) {
            foreach ($ids as $id) {
                $entry = self::entityFromDisk($dirs[$type], $type, $id);
                if ($entry !== null) {
                    $out["$type/$id"] = $entry;
                }
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null null when the folder holds no usable layer */
    private static function entityFromDisk(string $dir, string $type, string $id): ?array {
        $layers = self::layerEntriesFromDir($dir, $type, $id);
        if ($layers === []) {
            return null;
        }
        return [
            'expected_layers'          => $layers,
            'last_known_good_at'       => self::now(),
            'current_persistence_path' => rtrim($dir, '/'),
        ];
    }

    /**
     * #568: home ids that have layers on disk (configured user + every home found in
     * the persist folder) but no manifest entry. Read-only. The supervisor reconciles
     * these, as the manifest keys alone never list a home whose entry is lost.
     *
     * @return array<int, string>
     */
    public static function homeIdsMissingFromManifest(): array {
        $have = self::getAllEntities();
        $out  = [];
        foreach (self::idsOnDisk()['home'] as $id) {
            if (!isset($have["home/$id"])) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * #568: record a missing entity again from the layers on disk, in one locked
     * atomic write. True when the entity is in the manifest afterwards. False when no
     * usable layer exists for it, or the manifest is damaged and cannot be restored.
     * The caller holds the per-entity bake lock.
     */
    public static function rebuildEntity(string $entity): bool {
        if (strpos($entity, '/') === false) {
            return false;
        }
        [$type, $id] = explode('/', $entity, 2);
        $dirs = self::layerDirs();
        if (!isset($dirs[$type]) || $id === '' || self::isSmokeId($id)) {
            return false;
        }
        // R-044 (#622): hash the layers BEFORE the manifest lock. Hashing a layer on
        // the USB stick under LOCK_EX made every bake, consolidate and supervisor
        // tick wait. The locked block re-reads the manifest and only uses this
        // entry when the entity is still missing.
        $prepared = isset(self::getAllEntities()[$entity])
            ? null
            : self::entityFromDisk($dirs[$type], $type, $id);
        return self::withLock(function () use ($entity, $type, $id, $dirs, $prepared) {
            $fileWasGood = self::readManifestState()['state'] === 'ok';
            $manifest = self::loadForWrite();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }
            if (isset($manifest['entities'][$entity]) && $fileWasGood) {
                return true;
            }
            if (!isset($manifest['entities'][$entity])) {
                $entry = $prepared ?? self::entityFromDisk($dirs[$type], $type, $id);
                if ($entry === null) {
                    return false;
                }
                $manifest['entities'][$entity] = $entry;
            } else {
                // The file was damaged and loadForWrite restored or rebuilt this
                // entity: write the restored manifest back to the live path.
                $entry = $manifest['entities'][$entity];
            }
            $manifest['updated_at'] = self::now();
            $ok = self::atomicWrite($manifest);
            LifecycleLogService::log(
                $ok ? LifecycleLogService::LEVEL_WARN : LifecycleLogService::LEVEL_ERROR,
                self::COMPONENT,
                $ok ? 'manifest_entity_rebuilt' : 'manifest_entity_rebuild_failed',
                ['entity' => $entity, 'layers' => count($entry['expected_layers'])]
            );
            return $ok;
        });
    }

    /**
     * Returns a new empty manifest structure.
     *
     * @return array<string, mixed>
     */
    private static function emptyManifest(): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'updated_at'     => self::now(),
            'host_id'        => self::hostId(),
            'entities'       => [],
        ];
    }

    /**
     * Validates that the manifest's schema_version is compatible.
     * Logs WARN and returns false if schema is newer than we understand.
     *
     * @param array<string, mixed> $manifest
     */
    private static function checkSchemaVersion(array $manifest): bool {
        $version = (int)($manifest['schema_version'] ?? self::SCHEMA_VERSION);
        if ($version > self::SCHEMA_VERSION) {
            LifecycleLogService::log(
                LifecycleLogService::LEVEL_WARN,
                self::COMPONENT,
                'schema_version_too_new',
                ['found' => $version, 'supported' => self::SCHEMA_VERSION]
            );
            return false;
        }
        return true;
    }

    /**
     * Atomic write: write to .tmp.<pid>.<epoch>, fsync, rename to final path, fsync dir.
     *
     * @param array<string, mixed> $manifest
     */
    private static function atomicWrite(array $manifest): bool {
        $finalPath = StoragePathResolver::manifestPath();
        $dir       = dirname($finalPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpPath = $finalPath . '.tmp.' . getmypid() . '.' . time();
        $json    = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // Write to temp file
        $written = @file_put_contents($tmpPath, $json);
        if ($written === false) {
            @unlink($tmpPath);
            return false;
        }

        // fsync the temp file (PHP 8.1+; Unraid 7.2 ships PHP 8.2)
        $fd = @fopen($tmpPath, 'r+');
        if ($fd !== false) {
            if (function_exists('fsync')) {
                @fsync($fd);
            } else {
                // Durability degrades gracefully on older PHP — close+reopen is a no-op flush
                @fclose($fd);
                $fd = false;
            }
            if ($fd !== false) {
                @fclose($fd);
            }
        }

        // #569: keep the previous good generation as .bak. Only a file that still
        // parses is copied, so a damaged file never replaces a good .bak. The copy
        // goes through a temp name and a rename, so the .bak is never half written.
        if (self::parseFile($finalPath) !== null) {
            $bak    = self::bakPath();
            $bakTmp = $bak . '.tmp.' . getmypid();
            if (@copy($finalPath, $bakTmp) && @rename($bakTmp, $bak)) {
                // ok
            } else {
                @unlink($bakTmp);
                LifecycleLogService::log(LifecycleLogService::LEVEL_WARN, self::COMPONENT,
                    'manifest_bak_failed', ['path' => $bak]);
            }
        }

        // Atomic rename
        $renamed = @rename($tmpPath, $finalPath);
        if (!$renamed) {
            @unlink($tmpPath);
            return false;
        }

        // #569: there used to be a directory open/close block here that claimed to
        // fsync the parent directory. PHP cannot fsync a directory, so the block did
        // nothing and was removed. The rename is durable as far as the filesystem allows.
        return true;
    }

    /**
     * Acquires an exclusive flock on the lock file, runs $callback, releases lock.
     * Returns whatever $callback returns (expected: bool).
     */
    private static function withLock(callable $callback): bool {
        $lockPath = self::lockPath();
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $fd = @fopen($lockPath, 'c');
        if ($fd === false) {
            // If we can't create the lock file (e.g., /var/run missing at boot),
            // fall through without locking — durability degrades but doesn't break.
            return (bool)$callback();
        }

        $locked = @flock($fd, LOCK_EX);
        if (!$locked) {
            @fclose($fd);
            return false;
        }

        try {
            $result = (bool)$callback();
        } finally {
            @flock($fd, LOCK_UN);
            @fclose($fd);
        }

        return $result;
    }

    /**
     * Returns current UTC timestamp in ISO 8601 format.
     */
    private static function now(): string {
        return gmdate('Y-m-d\TH:i:s\Z', time());
    }

    /**
     * Returns a stable host identifier. Uses the machine-id if available,
     * otherwise falls back to a hash of the hostname.
     */
    private static function hostId(): string {
        $machineId = @file_get_contents('/etc/machine-id');
        if ($machineId !== false) {
            return trim($machineId);
        }
        return md5((string)gethostname());
    }
}
