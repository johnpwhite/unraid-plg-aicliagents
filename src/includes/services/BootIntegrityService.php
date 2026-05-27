<?php
/**
 * <module_context>
 *     <name>BootIntegrityService</name>
 *     <description>Phase 4a boot-time integrity classification and warn-mode sweep. Classifies each
 *     entity against the manifest and active layers, emits lifecycle log lines, and fires Unraid
 *     notifications for non-healthy states. Does NOT halt or block mounts in Phase 4a -- strict
 *     mode (halt-and-ask) is a Phase 4b config flip.</description>
 *     <dependencies>StoragePathResolver, LayerManifestService, LifecycleLogService, AgentRegistry, ConfigService</dependencies>
 *     <constraints>No mutations to manifest or layer files. Sibling discovery is read-only. Rate-limits
 *     notifications to once per (entity, state) per boot via /tmp/unraid-aicliagents/.boot_integrity_notified.
 *     Never calls exit() or throws exceptions -- returns classification arrays only.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class BootIntegrityService {

    // --- Classification states -------------------------------------------------

    public const STATE_GENUINE_FRESH    = 'genuine_fresh';
    public const STATE_HEALTHY          = 'healthy';
    public const STATE_LEGACY_UNMANAGED = 'legacy_unmanaged';
    public const STATE_PARTIAL_LOSS     = 'partial_loss';
    public const STATE_PATH_DRIFT       = 'path_drift';
    public const STATE_TOTAL_LOSS       = 'total_loss';
    public const STATE_UNTRACKED        = 'untracked';
    public const STATE_CORRUPT_LAYERS   = 'corrupt_layers';
    public const STATE_HOST_MISMATCH    = 'host_mismatch';

    private const COMPONENT      = 'BootIntegrityService';
    private const NOTIFIED_FILE  = '/tmp/unraid-aicliagents/.boot_integrity_notified';
    private const NOTIFY_SCRIPT  = '/usr/local/emhttp/plugins/dynamix/scripts/notify';

    /** States that map to Unraid "critical" severity. */
    private const CRITICAL_STATES = [
        self::STATE_TOTAL_LOSS,
        self::STATE_PARTIAL_LOSS,
        self::STATE_HOST_MISMATCH,
        self::STATE_CORRUPT_LAYERS,
    ];

    /** States that trigger a halt in strict mode (boot_integrity_strict=1). */
    private const HALT_STATES = [
        self::STATE_LEGACY_UNMANAGED,
        self::STATE_PATH_DRIFT,
        self::STATE_PARTIAL_LOSS,
        self::STATE_TOTAL_LOSS,
        self::STATE_CORRUPT_LAYERS,
        self::STATE_HOST_MISMATCH,
    ];

    /**
     * Map each halt state to its recommended recovery action.
     * path_drift is dynamic: restore_from_sibling if siblings present, else configure_path.
     */
    private const RECOMMENDED_ACTIONS = [
        self::STATE_LEGACY_UNMANAGED => 'restore_from_sibling',
        self::STATE_PATH_DRIFT       => 'restore_from_sibling',  // overridden per evidence
        self::STATE_PARTIAL_LOSS     => 'review_manifest',
        self::STATE_TOTAL_LOSS       => 'use_emergency_mode',
        self::STATE_CORRUPT_LAYERS   => 'review_manifest',
        self::STATE_HOST_MISMATCH    => 'review_manifest',
    ];

    // --- Public API ------------------------------------------------------------

    /**
     * Classify the boot integrity for a single entity.
     *
     * @param string $entity  'home/<user>' or 'agent/<id>'
     * @return array{state: string, evidence: array<string, mixed>}
     */
    public static function classifyEntity(string $entity): array {
        [$type, $id] = self::parseEntity($entity);
        if ($type === null || $id === null) {
            return [
                'state'    => self::STATE_GENUINE_FRESH,
                'evidence' => ['error' => 'Unparseable entity string'],
            ];
        }

        $persistPath = ($type === 'home')
            ? StoragePathResolver::homePersistPath($id)
            : StoragePathResolver::agentPersistPath();

        $manifestEntry  = LayerManifestService::getEntity($entity);
        $expectedLayers = $manifestEntry['expected_layers'] ?? [];
        $manifestPath   = $manifestEntry['current_persistence_path'] ?? null;

        $activeFiles = self::globLayers($persistPath, $type, $id);

        [$siblingFiles, $siblingPaths] = self::discoverSiblings($type, $id);

        $manifestHostId = self::readManifestHostId();
        $currentHostId  = self::currentHostId();
        $hostMismatch   = (
            $manifestHostId !== null
            && $currentHostId !== ''
            && $manifestHostId !== $currentHostId
        );

        $evidence = [
            'expected_count'      => count($expectedLayers),
            'active_count'        => count($activeFiles),
            'siblings_count'      => count($siblingFiles),
            'siblings_paths'      => array_slice($siblingPaths, 0, 5),
            'entity_persist_path' => $persistPath,
            'manifest_host_id'    => $manifestHostId,
            'current_host_id'     => $currentHostId,
        ];

        // Path drift: expected layers exist but manifest records a different path.
        $pathDrift = (
            !empty($expectedLayers)
            && $manifestPath !== null
            && rtrim($manifestPath, '/') !== rtrim($persistPath, '/')
        );

        if ($hostMismatch) {
            return ['state' => self::STATE_HOST_MISMATCH, 'evidence' => $evidence];
        }

        if (empty($expectedLayers)) {
            if (empty($activeFiles) && empty($siblingFiles)) {
                return ['state' => self::STATE_GENUINE_FRESH, 'evidence' => $evidence];
            }
            if (empty($activeFiles)) {
                return ['state' => self::STATE_LEGACY_UNMANAGED, 'evidence' => $evidence];
            }
            return ['state' => self::STATE_UNTRACKED, 'evidence' => $evidence];
        }

        // expected non-empty
        if (!empty($activeFiles)) {
            $activeBasenames = array_map('basename', $activeFiles);
            $missingCount    = 0;
            foreach ($expectedLayers as $layer) {
                $fn = $layer['filename'] ?? '';
                if ($fn !== '' && !in_array($fn, $activeBasenames, true)) {
                    $missingCount++;
                }
            }
            if ($missingCount === 0) {
                // Phase 4a: sha256 verify deferred to Phase 4b strict mode.
                return ['state' => self::STATE_HEALTHY, 'evidence' => $evidence];
            }
            $evidence['missing_count'] = $missingCount;
            return ['state' => self::STATE_PARTIAL_LOSS, 'evidence' => $evidence];
        }

        // expected non-empty, active empty
        if ($pathDrift || !empty($siblingFiles)) {
            if ($pathDrift) {
                $evidence['manifest_path'] = $manifestPath;
            }
            return ['state' => self::STATE_PATH_DRIFT, 'evidence' => $evidence];
        }
        return ['state' => self::STATE_TOTAL_LOSS, 'evidence' => $evidence];
    }

    /**
     * Run the full boot integrity sweep across all known entities.
     *
     * Entities swept: configured user home + all manifest entities + all installed agents.
     * Emits one lifecycle log line per entity plus a summary line.
     * Fires Unraid notifications for non-healthy states (rate-limited per boot).
     * Does NOT block mounts in Phase 4a warn mode.
     *
     * @return array<int, array{entity: string, state: string, evidence: array<string, mixed>}>
     */
    public static function runBootSweep(): array {
        $entities = self::collectEntities();

        $results        = [];
        $healthyCount   = 0;
        $freshCount     = 0;
        $attentionCount = 0;
        $anyCritical    = false;
        $anyWarning     = false;

        foreach ($entities as $entity) {
            [$entityType, $entityId] = self::parseEntity($entity) + [null, null];
            $entityType = $entityType ?? 'unknown';
            $entityId   = $entityId   ?? 'unknown';

            $classification = self::classifyEntity($entity);
            $state          = $classification['state'];
            $evidence       = $classification['evidence'];

            $results[] = [
                'entity'   => $entity,
                'state'    => $state,
                'evidence' => $evidence,
            ];

            LifecycleLogService::log(
                self::lifecycleLevel($state),
                self::COMPONENT,
                'boot_integrity_entity',
                array_merge(['entity' => $entity, 'state' => $state], $evidence)
            );

            if ($state !== self::STATE_HEALTHY && $state !== self::STATE_GENUINE_FRESH) {
                $attentionCount++;
                if (in_array($state, self::CRITICAL_STATES, true)) {
                    $anyCritical = true;
                } else {
                    $anyWarning = true;
                }
                self::notifyIfNotAlreadyFired($entity, $state, $evidence);

                // Phase 4b: set halt record when strict mode is active
                if (in_array($state, self::HALT_STATES, true)) {
                    self::maybeSetHalt($entityType, $entityId, $state, $evidence);
                }
            } elseif ($state === self::STATE_HEALTHY) {
                $healthyCount++;
            } else {
                $freshCount++;
            }
        }

        LifecycleLogService::log(
            LifecycleLogService::LEVEL_INFO,
            self::COMPONENT,
            'integrity_sweep_complete',
            [
                'entities_checked' => count($results),
                'healthy'          => $healthyCount,
                'fresh'            => $freshCount,
                'needs_attention'  => $attentionCount,
                'any_critical'     => $anyCritical,
                'any_warning'      => $anyWarning,
            ]
        );

        self::writeSweepCache($results, $healthyCount, $freshCount, $attentionCount, $anyCritical, $anyWarning);

        return $results;
    }

    /**
     * Returns the most-recently cached sweep result from tmpfs, or null if no sweep ran since boot.
     *
     * @return array<string, mixed>|null
     */
    public static function readCachedSweep(): ?array {
        $cachePath = '/tmp/unraid-aicliagents/.boot_integrity_cache.json';
        if (!file_exists($cachePath)) {
            return null;
        }
        $raw     = @file_get_contents($cachePath);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    // --- Entity collection -----------------------------------------------------

    /** @return string[] */
    private static function collectEntities(): array {
        $entities = [];

        $config = ConfigService::getConfig();
        $user   = $config['user'] ?? 'root';
        if (empty($user)) {
            $user = 'root';
        }
        $entities["home/$user"] = true;

        foreach (array_keys(LayerManifestService::getAllEntities()) as $me) {
            $entities[$me] = true;
        }

        try {
            foreach (AgentRegistry::getRegistry() as $agentId => $agent) {
                if (!empty($agent['is_installed'])) {
                    $entities["agent/$agentId"] = true;
                }
            }
        } catch (\Throwable $e) {
            LifecycleLogService::log(
                LifecycleLogService::LEVEL_WARN,
                self::COMPONENT,
                'agent_registry_unavailable',
                ['error' => $e->getMessage()]
            );
        }

        return array_keys($entities);
    }

    // --- Layer discovery -------------------------------------------------------

    /** @return string[] */
    private static function globLayers(string $persistPath, string $type, string $id): array {
        if (!is_dir($persistPath)) {
            return [];
        }
        $files = glob($persistPath . '/' . $type . '_' . $id . '_*.sqsh');
        return is_array($files) ? array_values(array_filter($files, 'is_file')) : [];
    }

    /**
     * Scan sibling directories under the plugin base for matching layer files.
     * Read-only -- never modifies or moves files.
     *
     * Sibling patterns (slashes elided in this comment to avoid closing the docblock):
     *   <plugin_base> / *backup* , *BACKUP* , migrated_legacy_data , *_old , *.bak , *.backup
     *   <parent_of_plugin_base> / unraid-aicliagents*
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function discoverSiblings(string $type, string $id): array {
        [$candidateDirs] = self::buildSiblingCandidateDirs();

        $allFiles     = [];
        $siblingPaths = [];
        foreach ($candidateDirs as $dir) {
            $found = @glob($dir . '/' . $type . '_' . $id . '_*.sqsh');
            if (is_array($found)) {
                foreach ($found as $f) {
                    if (is_file($f)) {
                        $allFiles[]     = $f;
                        $siblingPaths[] = $f;
                    }
                }
            }
        }

        return [$allFiles, $siblingPaths];
    }

    // --- Phase 4b: Halt gate ---------------------------------------------------

    /**
     * If boot_integrity_strict=1 is configured, write a halt record via HaltService.
     * Idempotent: re-writing an existing halt is harmless.
     */
    private static function maybeSetHalt(
        string $type,
        string $id,
        string $state,
        array  $evidence
    ): void {
        $config = ConfigService::getConfig();
        $strict = (string)($config['boot_integrity_strict'] ?? '1');
        if ($strict !== '1') {
            return;
        }

        // Determine recommended action — path_drift without siblings → configure_path
        $recommendedAction = self::RECOMMENDED_ACTIONS[$state] ?? 'review_manifest';
        if ($state === self::STATE_PATH_DRIFT) {
            $siblingsCount = (int)($evidence['siblings_count'] ?? 0);
            if ($siblingsCount === 0) {
                $recommendedAction = 'configure_path';
            }
        }

        HaltService::setHalt($type, $id, $state, $evidence, $recommendedAction);
    }

    // --- Notification ----------------------------------------------------------

    private static function notifyIfNotAlreadyFired(string $entity, string $state, array $evidence): void {
        $key = $entity . ':' . $state;

        if (file_exists(self::NOTIFIED_FILE)) {
            $lines = @file(self::NOTIFIED_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && in_array($key, $lines, true)) {
                return;
            }
        }

        $severity = in_array($state, self::CRITICAL_STATES, true) ? 'critical' : 'warning';
        $subject  = 'AICliAgents boot integrity: ' . $state . ' for ' . $entity;
        $desc     = self::buildNotificationDescription($entity, $state, $evidence);

        self::fireNotification($subject, $desc, $severity);

        $dir = dirname(self::NOTIFIED_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::NOTIFIED_FILE, $key . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function buildNotificationDescription(string $entity, string $state, array $evidence): string {
        $expected = (int)($evidence['expected_count'] ?? 0);
        $active   = (int)($evidence['active_count'] ?? 0);
        $siblings = (int)($evidence['siblings_count'] ?? 0);
        $path     = $evidence['entity_persist_path'] ?? 'unknown';

        switch ($state) {
            case self::STATE_TOTAL_LOSS:
                return "Manifest expects $expected layer(s) for $entity but none found at $path. "
                     . ($siblings > 0 ? "$siblings layer(s) in sibling directories. Recovery required." : "No sibling copies found.");
            case self::STATE_PARTIAL_LOSS:
                $missing = (int)($evidence['missing_count'] ?? ($expected - $active));
                return "Manifest expects $expected layer(s) for $entity but only $active found at $path. $missing missing.";
            case self::STATE_PATH_DRIFT:
                $mPath = $evidence['manifest_path'] ?? 'unknown';
                return "Manifest recorded layers at '$mPath' but active path is '$path'. "
                     . ($siblings > 0 ? "$siblings layer(s) in sibling directories." : "No layers found at either path.");
            case self::STATE_LEGACY_UNMANAGED:
                $spaths = implode(', ', array_slice($evidence['siblings_paths'] ?? [], 0, 3));
                return "No manifest entry for $entity but $siblings unmanaged layer(s) found: $spaths.";
            case self::STATE_UNTRACKED:
                return "Layers found at $path for $entity but none recorded in manifest. Supervisor will attempt recovery.";
            case self::STATE_HOST_MISMATCH:
                $mId = $evidence['manifest_host_id'] ?? 'unknown';
                $cId = $evidence['current_host_id'] ?? 'unknown';
                return "Manifest from host '$mId'; current host is '$cId'. USB may have moved between boxes.";
            case self::STATE_CORRUPT_LAYERS:
                return "Layer integrity check failed for $entity at $path. Manual review required.";
            default:
                return "Boot integrity state '$state' detected for $entity at $path.";
        }
    }

    private static function fireNotification(string $subject, string $description, string $severity): void {
        if (!file_exists(self::NOTIFY_SCRIPT)) {
            LifecycleLogService::log(
                LifecycleLogService::LEVEL_WARN,
                self::COMPONENT,
                'notify_script_missing',
                ['subject' => $subject]
            );
            return;
        }
        $lvl = escapeshellarg($severity);
        $sub = escapeshellarg($subject);
        $dsc = escapeshellarg($description);
        $cmd = self::NOTIFY_SCRIPT . " -e 'AICliAgents' -s $sub -d $dsc -i 'tasks' -l $lvl";
        exec($cmd . ' 2>/dev/null');
    }

    // --- Sweep cache (tmpfs) ---------------------------------------------------

    private static function writeSweepCache(
        array $results, int $healthyCount, int $freshCount,
        int $attentionCount, bool $anyCritical, bool $anyWarning
    ): void {
        $cache = [
            'sweep'        => $results,
            'summary'      => ['healthy' => $healthyCount, 'needs_attention' => $attentionCount, 'fresh' => $freshCount],
            'any_critical' => $anyCritical,
            'any_warning'  => $anyWarning,
            'swept_at'     => date('Y-m-d\TH:i:s\Z', time()),
        ];
        $cachePath = '/tmp/unraid-aicliagents/.boot_integrity_cache.json';
        $tmpPath   = $cachePath . '.tmp.' . getmypid() . '.' . time();
        $json      = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        if (@file_put_contents($tmpPath, $json) !== false) {
            @rename($tmpPath, $cachePath);
        } else {
            @unlink($tmpPath);
        }
    }

    // --- Phase 0: Sibling-restore API -----------------------------------------

    /**
     * Find all sibling layer files matching a given entity's naming pattern.
     *
     * Mirrors the bash discovery logic in boot_integrity.sh lines ~90-124.
     * Read-only — never moves or modifies files.
     *
     * Returns an array of records:
     *   ['source_path' => string, 'basename' => string, 'sibling_dir' => string,
     *    'sha256' => string|null, 'bytes' => int]
     *
     * @return array<int, array{source_path: string, basename: string, sibling_dir: string, sha256: string|null, bytes: int}>
     */
    public static function findSiblingLayers(string $type, string $id): array {
        [$candidateDirs] = self::buildSiblingCandidateDirs();

        $results = [];
        foreach ($candidateDirs as $dir) {
            $found = @glob($dir . '/' . $type . '_' . $id . '_*.sqsh');
            if (!is_array($found)) {
                continue;
            }
            foreach ($found as $f) {
                if (!is_file($f)) {
                    continue;
                }
                $sha256 = LayerManifestService::computeFileSha256($f);
                $bytes  = (int)(@filesize($f) ?: 0);
                $mtime  = (int)(@filemtime($f) ?: 0);
                $results[] = [
                    'source_path' => $f,
                    'basename'    => basename($f),
                    'sibling_dir' => $dir,
                    'sha256'      => $sha256,
                    'bytes'       => $bytes,
                    'mtime'       => $mtime,
                ];
            }
        }

        // Bug #540: deterministic ordering. Without this, restoreFromSibling
        // picked whichever candidate the filesystem returned first, which
        // could be an older or corrupt sibling when multiple backups exist.
        // Sort by mtime DESC (newest first), then by basename DESC as a
        // tiebreaker so canonical YYYYMMDD timestamps in filenames also
        // produce newest-first ordering when mtimes match.
        usort($results, function ($a, $b) {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcmp($b['basename'], $a['basename']);
        });

        return $results;
    }

    /**
     * Atomically restore sibling layer files into the active persist path
     * for the given entity, then register each in the manifest.
     *
     * Uses rename() (atomic on same filesystem — both are on Flash).
     * Falls back to copy-verify-delete if rename() fails (cross-filesystem).
     * Never overwrites an existing target file (collision guard).
     *
     * Returns:
     *   ['ok' => bool, 'restored' => int, 'skipped' => int, 'errors' => string[]]
     *
     * @return array{ok: bool, restored: int, skipped: int, errors: string[]}
     */
    public static function restoreFromSibling(string $type, string $id): array {
        $entity      = $type . '/' . $id;
        $activePath  = ($type === 'home')
            ? StoragePathResolver::homePersistPath($id)
            : StoragePathResolver::agentPersistPath();

        // Ensure the destination directory exists
        if (!is_dir($activePath)) {
            @mkdir($activePath, 0755, true);
        }

        $candidates = self::findSiblingLayers($type, $id);
        $restored   = 0;
        $skipped    = 0;
        $errors     = [];

        foreach ($candidates as $candidate) {
            $src      = $candidate['source_path'];
            $basename = $candidate['basename'];
            $target   = $activePath . '/' . $basename;

            // Collision guard: never overwrite existing target
            if (file_exists($target)) {
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_WARN,
                    self::COMPONENT,
                    'sibling_layer_restore_skipped',
                    ['entity' => $entity, 'basename' => $basename, 'reason' => 'target_exists']
                );
                $skipped++;
                continue;
            }

            $srcSha256 = $candidate['sha256'];
            $bytes     = $candidate['bytes'];

            // Attempt atomic rename (works when source and target are on the same filesystem)
            $moved = @rename($src, $target);

            if (!$moved) {
                // Fallback: copy, sha256-verify, then remove source
                $copied = @copy($src, $target);
                if (!$copied) {
                    $msg = "copy failed for $basename";
                    $errors[] = $msg;
                    LifecycleLogService::log(
                        LifecycleLogService::LEVEL_ERROR,
                        self::COMPONENT,
                        'sibling_layer_restore_error',
                        ['entity' => $entity, 'basename' => $basename, 'error' => $msg]
                    );
                    continue;
                }

                // Verify sha256 of the copy
                $copySha256 = LayerManifestService::computeFileSha256($target);
                if ($srcSha256 !== null && $copySha256 !== $srcSha256) {
                    @unlink($target);
                    $msg = "sha256 mismatch after copy for $basename";
                    $errors[] = $msg;
                    LifecycleLogService::log(
                        LifecycleLogService::LEVEL_ERROR,
                        self::COMPONENT,
                        'sibling_layer_restore_error',
                        ['entity' => $entity, 'basename' => $basename, 'error' => $msg]
                    );
                    continue;
                }

                // Remove source only after successful copy + verify
                @unlink($src);
            }

            // Re-verify sha256 of the file now at its target location
            $finalSha256 = LayerManifestService::computeFileSha256($target);
            if ($finalSha256 === null) {
                // Could not read the moved file — treat as error
                $msg = "could not read target after move for $basename";
                $errors[] = $msg;
                LifecycleLogService::log(
                    LifecycleLogService::LEVEL_ERROR,
                    self::COMPONENT,
                    'sibling_layer_restore_error',
                    ['entity' => $entity, 'basename' => $basename, 'error' => $msg]
                );
                continue;
            }

            // Register in manifest
            $layerEntry = [
                'filename'   => $basename,
                'sha256'     => $finalSha256,
                'bytes'      => $bytes,
                'kind'       => 'recovered',
                'created_at' => date('c'),
                'persist_path' => $activePath,
            ];
            LayerManifestService::addLayer($entity, $layerEntry);

            LifecycleLogService::log(
                LifecycleLogService::LEVEL_INFO,
                self::COMPONENT,
                'sibling_layer_restored',
                [
                    'entity'      => $entity,
                    'basename'    => $basename,
                    'sha256'      => $finalSha256,
                    'bytes'       => $bytes,
                    'target_path' => $target,
                ]
            );

            $restored++;
        }

        $ok = (count($errors) === 0 && $restored > 0) || ($restored > 0);

        if ($restored > 0) {
            LifecycleLogService::log(
                LifecycleLogService::LEVEL_INFO,
                self::COMPONENT,
                'legacy_unmanaged_recovery_complete',
                [
                    'entity'   => $entity,
                    'restored' => $restored,
                    'skipped'  => $skipped,
                    'errors'   => $errors,
                ]
            );
        }

        return [
            'ok'       => $ok,
            'restored' => $restored,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    // --- Internal helpers ------------------------------------------------------

    /**
     * Build the list of candidate sibling directories to scan.
     * Extracted for reuse by both discoverSiblings() and findSiblingLayers().
     *
     * @return array{0: string[]}
     */
    private static function buildSiblingCandidateDirs(): array {
        $pluginBase    = StoragePathResolver::PLUGIN_BASE;
        $parentDir     = dirname($pluginBase);
        $candidateDirs = [];

        $subDirs = @glob($pluginBase . '/*', GLOB_ONLYDIR);
        if (is_array($subDirs)) {
            foreach ($subDirs as $dir) {
                $base = basename($dir);
                if (
                    stripos($base, 'backup') !== false
                    || $base === 'migrated_legacy_data'
                    || substr($base, -4) === '_old'
                    || substr($base, -4) === '.bak'
                    || substr($base, -7) === '.backup'
                ) {
                    $candidateDirs[] = $dir;
                }
            }
        }

        $parentSiblings = @glob($parentDir . '/unraid-aicliagents*', GLOB_ONLYDIR);
        if (is_array($parentSiblings)) {
            foreach ($parentSiblings as $dir) {
                if ($dir !== $pluginBase) {
                    $candidateDirs[] = $dir;
                }
            }
        }

        return [$candidateDirs];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function parseEntity(string $entity): array {
        $parts = explode('/', $entity, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }
        if ($parts[0] !== 'home' && $parts[0] !== 'agent') {
            return [null, null];
        }
        return [$parts[0], $parts[1]];
    }

    private static function lifecycleLevel(string $state): string {
        if (in_array($state, self::CRITICAL_STATES, true)) {
            return LifecycleLogService::LEVEL_CRITICAL;
        }
        if (in_array($state, [self::STATE_LEGACY_UNMANAGED, self::STATE_PATH_DRIFT, self::STATE_UNTRACKED], true)) {
            return LifecycleLogService::LEVEL_WARN;
        }
        return LifecycleLogService::LEVEL_INFO;
    }

    private static function readManifestHostId(): ?string {
        $path = StoragePathResolver::manifestPath();
        if (!file_exists($path)) {
            return null;
        }
        $raw     = @file_get_contents($path);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return null;
        }
        $id = $decoded['host_id'] ?? null;
        return is_string($id) ? trim($id) : null;
    }

    private static function currentHostId(): string {
        $raw = @file_get_contents('/etc/machine-id');
        return ($raw !== false) ? trim($raw) : '';
    }
}