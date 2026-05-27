<?php
/**
 * <module_context>
 *     <name>HaltService</name>
 *     <description>Phase 4b: Authoritative writer/reader for boot-integrity halt state.
 *     Every halt transition goes through this class — no direct file writes from outside.
 *     All transitions are written to the lifecycle log via LifecycleLogService.</description>
 *     <dependencies>LifecycleLogService</dependencies>
 *     <constraints>Atomic writes (write-tmp + rename). Never throws — returns bool/null/array.
 *     No closing PHP tag per project convention.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class HaltService {

    public const HALT_DIR = '/tmp/unraid-aicliagents/supervisor/halts';

    private const COMPONENT = 'HaltService';

    // Recommended actions per state (used by UI to choose recovery path)
    private const RECOMMENDED_ACTIONS = [
        'legacy_unmanaged' => 'restore_from_sibling',
        'path_drift'       => 'restore_from_sibling',   // overridden in setHalt if no siblings
        'partial_loss'     => 'review_manifest',
        'total_loss'       => 'use_emergency_mode',
        'corrupt_layers'   => 'review_manifest',
        'host_mismatch'    => 'review_manifest',
    ];

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Check if an entity is currently halted.
     *
     * @param string $type  'home' or 'agent'
     * @param string $id    username or agent-id
     */
    public static function isHalted(string $type, string $id): bool {
        $path = self::haltFilePath($type, $id);
        return file_exists($path);
    }

    /**
     * Read the sidecar JSON for a halted entity. Returns null if not halted or
     * sidecar is unreadable.
     *
     * @return array<string, mixed>|null
     */
    public static function getHalt(string $type, string $id): ?array {
        $sidecar = self::sidecarPath($type, $id);
        if (!file_exists($sidecar)) {
            return null;
        }
        $raw     = @file_get_contents($sidecar);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Scan all halts and return a sorted list of halt records.
     * Sorted by type then id for deterministic ordering.
     *
     * @return array<int, array{type: string, id: string, state: string, halted_at: string, details: array, recommended_action: string}>
     */
    public static function listAllHalts(): array {
        $haltDir = self::HALT_DIR;
        if (!is_dir($haltDir)) {
            return [];
        }

        $results = [];

        $typeDirs = @glob($haltDir . '/*', GLOB_ONLYDIR);
        if (!is_array($typeDirs)) {
            return [];
        }

        foreach ($typeDirs as $typeDir) {
            $type  = basename($typeDir);
            $files = @glob($typeDir . '/*');
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $f) {
                // Skip sidecar .json files — we read those via getHalt()
                if (substr($f, -5) === '.json') {
                    continue;
                }
                $id      = basename($f);
                $sidecar = self::getHalt($type, $id);
                if ($sidecar !== null) {
                    $results[] = $sidecar;
                } else {
                    // State-only file with no sidecar — build a minimal record
                    $state    = trim((string)(@file_get_contents($f) ?: 'unknown'));
                    $results[] = [
                        'type'               => $type,
                        'id'                 => $id,
                        'state'              => $state,
                        'halted_at'          => '',
                        'details'            => [],
                        'recommended_action' => self::RECOMMENDED_ACTIONS[$state] ?? 'review_manifest',
                    ];
                }
            }
        }

        // Sort by type then id for deterministic order
        usort($results, function (array $a, array $b): int {
            $cmp = strcmp($a['type'] ?? '', $b['type'] ?? '');
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['id'] ?? '', $b['id'] ?? '');
        });

        return $results;
    }

    /**
     * Write a halt record for an entity.
     *
     * @param string              $type
     * @param string              $id
     * @param string              $state            One of the BootIntegrityService::STATE_* values
     * @param array<string,mixed> $details          Diagnostic context (evidence from classifyEntity)
     * @param string              $recommendedAction One of: restore_from_sibling, use_emergency_mode,
     *                                              configure_path, review_manifest
     */
    public static function setHalt(
        string $type,
        string $id,
        string $state,
        array  $details,
        string $recommendedAction
    ): bool {
        $dir = self::HALT_DIR . '/' . $type;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $haltFile = $dir . '/' . $id;
        $sidecarFile = $haltFile . '.json';
        $haltedAt = gmdate('Y-m-d\TH:i:s\Z');

        // Write the state-only file (content is the state string)
        if (!AtomicWriteService::write($haltFile, $state)) {
            return false;
        }

        // Build and atomically write the sidecar JSON
        $sidecar = [
            'type'               => $type,
            'id'                 => $id,
            'state'              => $state,
            'halted_at'          => $haltedAt,
            'details'            => $details,
            'recommended_action' => $recommendedAction,
        ];

        $json    = json_encode($sidecar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        $tmpPath = $sidecarFile . '.tmp.' . getmypid() . '.' . time();
        if (@file_put_contents($tmpPath, $json) === false) {
            @unlink($tmpPath);
            return false;
        }
        if (!@rename($tmpPath, $sidecarFile)) {
            @unlink($tmpPath);
            return false;
        }

        LifecycleLogService::log(
            LifecycleLogService::LEVEL_CRITICAL,
            self::COMPONENT,
            'halt_set',
            [
                'type'               => $type,
                'id'                 => $id,
                'state'              => $state,
                'recommended_action' => $recommendedAction,
                'halted_at'          => $haltedAt,
            ]
        );

        return true;
    }

    /**
     * Clear a halt record for an entity.
     * Removes both the state file and sidecar JSON.
     * Logs the transition via the lifecycle log.
     *
     * @param string $type
     * @param string $id
     * @param string $reason  Human-readable reason for clearing (e.g. 'restore_from_sibling', 'user_override')
     */
    public static function clearHalt(string $type, string $id, string $reason): bool {
        $haltFile    = self::haltFilePath($type, $id);
        $sidecarFile = self::sidecarPath($type, $id);

        // Read current state for the log entry before removing
        $priorState = '';
        if (file_exists($haltFile)) {
            $priorState = trim((string)(@file_get_contents($haltFile) ?: ''));
        }

        $removed = true;
        if (file_exists($haltFile) && !@unlink($haltFile)) {
            $removed = false;
        }
        if (file_exists($sidecarFile)) {
            @unlink($sidecarFile);
        }

        LifecycleLogService::log(
            LifecycleLogService::LEVEL_INFO,
            self::COMPONENT,
            'halt_cleared',
            [
                'type'        => $type,
                'id'          => $id,
                'prior_state' => $priorState,
                'reason'      => $reason,
                'cleared_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            ]
        );

        return $removed;
    }

    // ---------------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------------

    private static function haltFilePath(string $type, string $id): string {
        return self::HALT_DIR . '/' . $type . '/' . $id;
    }

    private static function sidecarPath(string $type, string $id): string {
        return self::haltFilePath($type, $id) . '.json';
    }
}
