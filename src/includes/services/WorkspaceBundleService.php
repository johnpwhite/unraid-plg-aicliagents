<?php
/**
 * <module_context>
 *     <name>WorkspaceBundleService</name>
 *     <description>Export and import workspace configuration bundles (T-11, Feature #1360).
 *     Export: collects per-workspace tier files (args, env, tmux, .conf, auto-launch) into a
 *     tar.gz bundle at /tmp/unraid-aicliagents/bundles/. Secrets excluded by default; opt-in only.
 *     Import: validates the bundle manifest (schema, path allowlist, size caps, no traversal/symlinks),
 *     rewrites hash-keyed filenames for the new workspace path, and writes via existing service save
 *     methods so atomicity and validation ride along.</description>
 *     <dependencies>ArgsService, EnvService, TmuxService, ConfigService, SecretService, AtomicWriteService, LogService</dependencies>
 *     <constraints>Static methods only. NEVER logs secret values. Bundle ≤1 MB total, each member ≤256 KB.
 *     Member paths must match the expected tier filename patterns — no arbitrary paths, no traversal,
 *     no symlinks. Uses proc_open array form for tar (no shell expansion).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class WorkspaceBundleService
{
    const BUNDLE_DIR       = '/tmp/unraid-aicliagents/bundles';
    const BUNDLE_MAX_BYTES = 1048576;  // 1 MB
    const MEMBER_MAX_BYTES = 262144;   // 256 KB
    const SCHEMA_VERSION   = 1;

    // Known member names (relative paths inside the tar) — allowlist for import.
    // Any file in the archive that doesn't match one of these is rejected.
    private const KNOWN_MEMBERS = [
        'bundle.json',
        'args_ws.json',
        'env_ws.json',
        'tmux_ws.json',
        'tmux_agent.json',
        'tmux_ws.conf',
        'autolaunch.json',
        'secrets_ws.cfg',
    ];

    // ---------- Export ----------

    /**
     * Build a tar.gz bundle for the given workspace + agent.
     *
     * @param string $workspacePath  Absolute path to the workspace directory.
     * @param string $agentId        Agent identifier (e.g. 'claude-code').
     * @param bool   $includeSecrets When true, workspace secrets are included and
     *                               bundle.json flags includes_secrets=true.
     *                               SECRETS ARE EXCLUDED BY DEFAULT.
     * @return array{status:string, file?:string, size?:int, message?:string}
     */
    public static function export(string $workspacePath, string $agentId, bool $includeSecrets = false): array
    {
        try {
            self::ensureBundleDir();

            $ts        = time();
            $sanitized = self::sanitizePathForFilename($workspacePath);
            $bundleName = "aicli-workspace-{$sanitized}-{$ts}.tar.gz";
            $bundlePath = self::BUNDLE_DIR . '/' . $bundleName;

            // Collect tier files that EXIST on disk.
            $staging    = [];
            $filesInBundle = [];

            // 1. Workspace args JSON
            $argsPath = ArgsService::getWorkspaceArgsPath($workspacePath, $agentId);
            if (file_exists($argsPath)) {
                $staging['args_ws.json'] = $argsPath;
                $filesInBundle[] = 'args_ws.json';
            }

            // 2. Workspace env JSON (EnvService hash = md5($path.$agentId))
            $envPath = EnvService::getWorkspaceEnvPath($workspacePath, $agentId);
            if (file_exists($envPath)) {
                $staging['env_ws.json'] = $envPath;
                $filesInBundle[] = 'env_ws.json';
            }

            // 3. Workspace tmux JSON
            $tmuxWsPath = TmuxService::getWorkspaceSettingsPath($workspacePath, $agentId);
            if (file_exists($tmuxWsPath)) {
                $staging['tmux_ws.json'] = $tmuxWsPath;
                $filesInBundle[] = 'tmux_ws.json';
            }

            // 4. Agent tmux JSON (agent-level defaults)
            $tmuxAgentPath = TmuxService::getAgentSettingsPath($agentId);
            if (file_exists($tmuxAgentPath)) {
                $staging['tmux_agent.json'] = $tmuxAgentPath;
                $filesInBundle[] = 'tmux_agent.json';
            }

            // 5. Raw .conf file at <workspace>/.aicli/tmux/<agentId>.conf
            $confPath = TmuxService::getConfPath($workspacePath, $agentId);
            if (file_exists($confPath)) {
                $staging['tmux_ws.conf'] = $confPath;
                $filesInBundle[] = 'tmux_ws.conf';
            }

            // 6. Auto-launch entry (JSON fragment)
            $alData = ConfigService::getAutoLaunch($workspacePath, $agentId);
            // Only include if auto-launch is actually enabled (non-default)
            if (!empty($alData['autoLaunch'])) {
                $alJson = json_encode($alData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $alTmp  = self::BUNDLE_DIR . "/tmp_autolaunch_{$ts}.json";
                file_put_contents($alTmp, $alJson);
                $staging['autolaunch.json'] = $alTmp;
                $filesInBundle[] = 'autolaunch.json';
            }

            // 7. Workspace secrets (only when opted in)
            $secretsTmpPath = null;
            if ($includeSecrets) {
                $secretsPath = SecretService::getWorkspaceSecretsPath($workspacePath, $agentId);
                if (file_exists($secretsPath)) {
                    $staging['secrets_ws.cfg'] = $secretsPath;
                    $filesInBundle[] = 'secrets_ws.cfg';
                }
            }

            // Build manifest.
            $manifest = [
                'schema'           => self::SCHEMA_VERSION,
                'exported_at'      => $ts,
                'plugin_version'   => ConfigService::getVersion(),
                'workspace_path'   => $workspacePath,
                'agent_id'         => $agentId,
                'includes_secrets' => $includeSecrets && in_array('secrets_ws.cfg', $filesInBundle, true),
                'files'            => $filesInBundle,
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $manifestTmp  = self::BUNDLE_DIR . "/tmp_manifest_{$ts}.json";
            file_put_contents($manifestTmp, $manifestJson);
            $staging['bundle.json'] = $manifestTmp;

            // Build the tar.gz using proc_open array form (no shell expansion).
            // We create a temp staging directory with symlink-free copies.
            $stagingDir = self::BUNDLE_DIR . "/staging_{$ts}_" . getmypid();
            if (!mkdir($stagingDir, 0700, true)) {
                return ['status' => 'error', 'message' => 'Failed to create staging directory'];
            }

            try {
                foreach ($staging as $memberName => $srcPath) {
                    // Hard-copy (not symlink) to staging dir.
                    // Guard: only copy regular files — copy() on a FIFO/socket blocks forever.
                    if (!is_file($srcPath)) {
                        LogService::log("WorkspaceBundleService::export skipping non-regular file: $srcPath", LogService::LOG_WARN, 'Bundle');
                        continue;
                    }
                    copy($srcPath, $stagingDir . '/' . $memberName);
                }

                // tar czf bundlePath -C stagingDir .
                // Redirect stdout to /dev/null — tar writes the archive to -f $bundlePath
                // (not stdout), but the pipe must still be drained: if stdout ever accumulates
                // ≥64 KB (e.g. a future -v flag or a tar version that emits progress to fd 1),
                // an unread stdout pipe would fill, block tar, and deadlock stream_get_contents
                // on stderr. /dev/null drain avoids the race without requiring non-blocking I/O.
                $cmd  = ['tar', 'czf', $bundlePath, '-C', $stagingDir, '.'];
                $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($proc)) {
                    return ['status' => 'error', 'message' => 'Failed to start tar process'];
                }
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $rc = proc_close($proc);
                if ($rc !== 0) {
                    LogService::log("WorkspaceBundleService::export tar failed (rc=$rc): $stderr", LogService::LOG_ERROR, 'Bundle');
                    return ['status' => 'error', 'message' => "tar failed: $stderr"];
                }
            } finally {
                // Clean up staging dir and temp files.
                self::rmrf($stagingDir);
                @unlink($manifestTmp);
                if (isset($alTmp)) @unlink($alTmp);
            }

            $size = filesize($bundlePath) ?: 0;
            LogService::log("Bundle exported: $bundleName ({$size}B) for $workspacePath / $agentId (secrets=" . ($includeSecrets ? 'yes' : 'no') . ')', LogService::LOG_INFO, 'Bundle');
            return ['status' => 'ok', 'file' => $bundleName, 'size' => $size];

        } catch (\Throwable $e) {
            LogService::log('Bundle export failed: ' . $e->getMessage(), LogService::LOG_ERROR, 'Bundle');
            return ['status' => 'error', 'message' => 'Export failed: ' . $e->getMessage()];
        }
    }

    // ---------- Import ----------

    /**
     * Import a workspace bundle onto a new workspace path.
     *
     * @param string $tarPath          Absolute path to the uploaded tar.gz.
     * @param string $newWorkspacePath Target workspace root path.
     * @param string $agentId          Agent identifier.
     * @param bool   $acceptSecrets    Must be true to apply a secrets_ws.cfg member.
     *                                 Rejected silently otherwise (secrets dropped, not error).
     * @return array{status:string, applied?:string[], skipped?:string[], message?:string}
     */
    public static function import(string $tarPath, string $newWorkspacePath, string $agentId, bool $acceptSecrets = false): array
    {
        try {
            // -- 1. Pre-flight size cap on the archive itself --
            $archiveSize = filesize($tarPath);
            if ($archiveSize === false || $archiveSize > self::BUNDLE_MAX_BYTES) {
                return ['status' => 'error', 'message' => 'Bundle exceeds 1 MB size cap'];
            }

            // -- 2. Extract to a temp dir --
            $extractDir = sys_get_temp_dir() . '/aicli-import-' . getmypid() . '-' . time();
            if (!mkdir($extractDir, 0700, true)) {
                return ['status' => 'error', 'message' => 'Failed to create extract directory'];
            }

            try {
                // Same stdout-drain guard as export: redirect stdout to /dev/null so
                // a future tar emitting progress/listing to fd 1 cannot deadlock the
                // stream_get_contents($pipes[2]) stderr read.
                $cmd  = ['tar', 'xzf', $tarPath, '-C', $extractDir];
                $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($proc)) {
                    return ['status' => 'error', 'message' => 'Failed to start tar process for extraction'];
                }
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $rc = proc_close($proc);
                if ($rc !== 0) {
                    return ['status' => 'error', 'message' => 'Extraction failed: ' . $stderr];
                }

                return self::processExtracted($extractDir, $newWorkspacePath, $agentId, $acceptSecrets);

            } finally {
                self::rmrf($extractDir);
            }

        } catch (\Throwable $e) {
            LogService::log('Bundle import failed: ' . $e->getMessage(), LogService::LOG_ERROR, 'Bundle');
            return ['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()];
        }
    }

    // ---------- Security validation + path-traversal guard (for download) ----------

    /**
     * Validate that $raw is a safe bundle basename (no path separators, correct naming scheme).
     * Mirrors DiagnosticsHandler::safeBundleName pattern.
     */
    public static function safeBundleName(string $raw): ?string
    {
        $raw = (string)$raw;
        if ($raw === '' || basename($raw) !== $raw) return null;
        if (!preg_match('/^aicli-workspace-[A-Za-z0-9._-]+-[0-9]+\.tar\.gz$/', $raw)) return null;
        if (strpos($raw, '..') !== false) return null;
        return $raw;
    }

    // ---------- Internal ----------

    private static function processExtracted(string $extractDir, string $newWorkspacePath, string $agentId, bool $acceptSecrets): array
    {
        // -- 3. Validate manifest --
        $manifestFile = $extractDir . '/bundle.json';
        if (!file_exists($manifestFile)) {
            return ['status' => 'error', 'message' => 'bundle.json missing from archive'];
        }
        if (filesize($manifestFile) > self::MEMBER_MAX_BYTES) {
            return ['status' => 'error', 'message' => 'bundle.json exceeds 256 KB member cap'];
        }
        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return ['status' => 'error', 'message' => 'bundle.json is not valid JSON'];
        }
        if (($manifest['schema'] ?? null) !== self::SCHEMA_VERSION) {
            return ['status' => 'error', 'message' => 'Unsupported bundle schema version: ' . ($manifest['schema'] ?? 'none')];
        }
        if (empty($manifest['agent_id']) || empty($manifest['workspace_path'])) {
            return ['status' => 'error', 'message' => 'bundle.json missing required fields (agent_id, workspace_path)'];
        }

        // -- 4. Enumerate extracted members — check for symlinks, traversal, unexpected names --
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            // Reject symlinks unconditionally.
            if ($entry->isLink()) {
                return ['status' => 'error', 'message' => 'Archive contains a symlink — rejected'];
            }
            if ($entry->isFile()) {
                // Compute path relative to $extractDir.
                $relPath = ltrim(substr($entry->getPathname(), strlen($extractDir)), '/');
                // No subdirectory nesting allowed.
                if (str_contains($relPath, '/')) {
                    return ['status' => 'error', 'message' => "Unexpected path depth in archive: $relPath"];
                }
                // Must be in the allowlist.
                if (!in_array($relPath, self::KNOWN_MEMBERS, true)) {
                    return ['status' => 'error', 'message' => "Unexpected archive member: $relPath"];
                }
                // Per-member size cap.
                if ($entry->getSize() > self::MEMBER_MAX_BYTES) {
                    return ['status' => 'error', 'message' => "Archive member '$relPath' exceeds 256 KB cap"];
                }
                // No parent references in the content path itself.
                if (strpos($relPath, '..') !== false) {
                    return ['status' => 'error', 'message' => "Path traversal detected in member name: $relPath"];
                }
            }
        }

        // -- 5. Apply tier files via the existing services (atomicity + validation ride along) --
        $applied = [];
        $skipped = [];

        // args_ws.json
        $argsFile = $extractDir . '/args_ws.json';
        if (file_exists($argsFile)) {
            $data = json_decode((string)file_get_contents($argsFile), true);
            $args = is_array($data) ? ($data['args'] ?? '') : '';
            if ($args !== '') {
                $rejected = ArgsService::validateArgs($args);
                if (empty($rejected)) {
                    ArgsService::saveWorkspaceArgs($newWorkspacePath, $agentId, $args);
                    $applied[] = 'args_ws.json';
                } else {
                    $skipped[] = 'args_ws.json (invalid args: ' . implode(', ', $rejected) . ')';
                }
            } else {
                $skipped[] = 'args_ws.json (empty)';
            }
        }

        // env_ws.json
        $envFile = $extractDir . '/env_ws.json';
        if (file_exists($envFile)) {
            $rawMap = json_decode((string)file_get_contents($envFile), true);
            if (is_array($rawMap) && !empty($rawMap)) {
                [$cleanMap, $rejected] = EnvService::validateMap($rawMap);
                if (!empty($cleanMap)) {
                    EnvService::saveWorkspaceEnvs($newWorkspacePath, $agentId, $cleanMap);
                    $applied[] = 'env_ws.json';
                    if (!empty($rejected)) {
                        $skipped[] = 'env_ws.json (some keys rejected: ' . implode('; ', array_map(fn($r) => $r[0] . ': ' . $r[1], $rejected)) . ')';
                    }
                } else {
                    $skipped[] = 'env_ws.json (all keys invalid/reserved)';
                }
            } else {
                $skipped[] = 'env_ws.json (empty)';
            }
        }

        // tmux_ws.json
        $tmuxWsFile = $extractDir . '/tmux_ws.json';
        if (file_exists($tmuxWsFile)) {
            $settings = json_decode((string)file_get_contents($tmuxWsFile), true);
            if (is_array($settings) && !empty($settings)) {
                TmuxService::saveWorkspaceOverrides($newWorkspacePath, $agentId, $settings);
                $applied[] = 'tmux_ws.json';
            } else {
                $skipped[] = 'tmux_ws.json (empty)';
            }
        }

        // tmux_agent.json — note: this applies to the AGENT tier, not workspace-specific.
        // We import it only if the bundle's agentId matches the target agentId.
        $tmuxAgentFile = $extractDir . '/tmux_agent.json';
        if (file_exists($tmuxAgentFile) && ($manifest['agent_id'] ?? '') === $agentId) {
            $settings = json_decode((string)file_get_contents($tmuxAgentFile), true);
            if (is_array($settings) && !empty($settings)) {
                TmuxService::saveAgentDefaults($agentId, $settings);
                $applied[] = 'tmux_agent.json';
            } else {
                $skipped[] = 'tmux_agent.json (empty)';
            }
        } elseif (file_exists($tmuxAgentFile)) {
            $skipped[] = 'tmux_agent.json (agent id mismatch — bundle: ' . ($manifest['agent_id'] ?? '?') . ', target: ' . $agentId . ')';
        }

        // tmux_ws.conf — raw conf file written to the workspace's .aicli/tmux/ directory.
        $confFile = $extractDir . '/tmux_ws.conf';
        if (file_exists($confFile)) {
            $targetConf = TmuxService::getConfPath($newWorkspacePath, $agentId);
            $confDir    = dirname($targetConf);
            if (!is_dir($confDir)) {
                @mkdir($confDir, 0755, true);
            }
            $content = file_get_contents($confFile);
            if (AtomicWriteService::write($targetConf, (string)$content)) {
                $applied[] = 'tmux_ws.conf';
            } else {
                $skipped[] = 'tmux_ws.conf (write failed)';
            }
        }

        // autolaunch.json
        $alFile = $extractDir . '/autolaunch.json';
        if (file_exists($alFile)) {
            $alData = json_decode((string)file_get_contents($alFile), true);
            if (is_array($alData) && isset($alData['autoLaunch'])) {
                ConfigService::saveAutoLaunch(
                    $newWorkspacePath,
                    $agentId,
                    (bool)($alData['autoLaunch'] ?? false),
                    (bool)($alData['freshIfNoResume'] ?? false)
                );
                $applied[] = 'autolaunch.json';
            } else {
                $skipped[] = 'autolaunch.json (invalid format)';
            }
        }

        // secrets_ws.cfg — only applied when acceptSecrets=true AND bundle flagged includes_secrets.
        $secretsFile = $extractDir . '/secrets_ws.cfg';
        if (file_exists($secretsFile)) {
            if ($acceptSecrets && !empty($manifest['includes_secrets'])) {
                $parsed = @parse_ini_file($secretsFile);
                if (is_array($parsed) && !empty($parsed)) {
                    $clean = [];
                    foreach ($parsed as $k => $v) {
                        if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', (string)$k)) {
                            $clean[$k] = (string)$v;
                        }
                    }
                    if (!empty($clean)) {
                        SecretService::saveWorkspaceSecrets($newWorkspacePath, $agentId, $clean);
                        $applied[] = 'secrets_ws.cfg';
                    } else {
                        $skipped[] = 'secrets_ws.cfg (no valid keys after allowlist gate)';
                    }
                } else {
                    $skipped[] = 'secrets_ws.cfg (parse failed or empty)';
                }
            } else {
                // Silently drop — not an error condition.
                $skipped[] = 'secrets_ws.cfg (not accepted — accept_secrets not set or bundle did not flag includes_secrets)';
            }
        }

        LogService::log(
            'Bundle imported onto ' . $newWorkspacePath . '/' . $agentId . ': applied=[' . implode(',', $applied) . '] skipped=[' . implode(';', $skipped) . ']',
            LogService::LOG_INFO,
            'Bundle'
        );

        return [
            'status'     => 'ok',
            'applied'    => $applied,
            'skipped'    => $skipped,
            'bundle_agent_id' => $manifest['agent_id'] ?? '',
        ];
    }

    private static function ensureBundleDir(): void
    {
        if (!is_dir(self::BUNDLE_DIR)) {
            @mkdir(self::BUNDLE_DIR, 0700, true);
        }
    }

    /**
     * Produce a filesystem-safe slug from a workspace path.
     * Keeps alphanum and dashes; collapses runs of non-safe chars to '-'.
     */
    private static function sanitizePathForFilename(string $path): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $path) ?? '-';
        $slug = trim($slug, '-');
        return substr($slug ?: 'workspace', 0, 60);
    }

    /**
     * Recursively delete a directory. Best-effort; any failure is swallowed.
     */
    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
