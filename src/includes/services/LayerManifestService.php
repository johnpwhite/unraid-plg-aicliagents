<?php
/**
 * <module_context>
 *     <name>LayerManifestService</name>
 *     <description>Owns the durable layer manifest at /boot/config/plugins/unraid-aicliagents/layer_manifest.json. Single source of truth for which layers belong to which entity.</description>
 *     <dependencies>StoragePathResolver, LifecycleLogService</dependencies>
 *     <constraints>All mutating methods are flock-protected. Writes are atomic (tmp + fsync + rename). Never modifies manifests with schema_version > 1.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class LayerManifestService {
    public const SCHEMA_VERSION = 1;

    /** Lock file on tmpfs — cheap, no flash wear, released on crash (POSIX flock). */
    private const LOCK_PATH = '/var/run/aicli-supervisor.manifest.lock';

    private const COMPONENT = 'LayerManifestService';

    // -----------------------------------------------------------------------
    // Read methods (no locking required — reads are atomic on ext4/vfat)
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
        return array_values($out);
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
     * @return array<string, mixed>|null
     */
    private static function readManifest(): ?array {
        $path = StoragePathResolver::manifestPath();
        if (!file_exists($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
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
        return date('Y-m-d\TH:i:s\Z', time());
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
