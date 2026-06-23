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

    public static function addLayer(string $entity, array $layerEntry): bool {
        return self::withLock(function () use ($entity, $layerEntry) {
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
     * Removes a layer entry by filename from an entity.
     */
    public static function removeLayer(string $entity, string $filename): bool {
        return self::withLock(function () use ($entity, $filename) {
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
            $manifest = self::readManifest();
            if ($manifest === null || !self::checkSchemaVersion($manifest)) {
                return false;
            }
            $count = 0;
            foreach (($manifest['entities'] ?? []) as $entity => &$e) {
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
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
            $manifest = self::readManifest() ?? self::emptyManifest();
            if (!self::checkSchemaVersion($manifest)) {
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
        $path = StoragePathResolver::manifestPath();
        if (!file_exists($path)) {
            return null;
        }
        // Best-effort LOCK_SH (belt-and-braces vs the bash writer's LOCK_EX).
        // MUST be non-blocking: readManifest is also called from inside withLock's
        // LOCK_EX (same process, different fd — flock treats those as conflicting),
        // so a blocking LOCK_SH here would self-deadlock every mutator.
        $lockFd   = @fopen(self::LOCK_PATH, 'c');
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
                    return $decoded;
                }
            }
            return null;
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

        // Atomic rename
        $renamed = @rename($tmpPath, $finalPath);
        if (!$renamed) {
            @unlink($tmpPath);
            return false;
        }

        // fsync the parent directory (ensures the rename is durable)
        $dirFd = @opendir($dir);
        if ($dirFd !== false) {
            // PHP has no dir-fd fsync; closest is re-opening the dir as a file.
            // On Linux, fsync(open(dir)) is the POSIX pattern. We use a no-op
            // here since PHP doesn't expose this — the rename itself flushes on
            // most Linux kernels with default mount options.
            closedir($dirFd);
        }

        return true;
    }

    /**
     * Acquires an exclusive flock on the lock file, runs $callback, releases lock.
     * Returns whatever $callback returns (expected: bool).
     */
    private static function withLock(callable $callback): bool {
        $lockDir = dirname(self::LOCK_PATH);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $fd = @fopen(self::LOCK_PATH, 'c');
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
