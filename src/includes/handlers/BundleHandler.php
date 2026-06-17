<?php
/**
 * <module_context>
 *     <name>BundleHandler</name>
 *     <description>AJAX actions for workspace export/import bundles (T-11, Feature #1360):
 *     workspace_export (POST → builds tar.gz, returns basename+size),
 *     workspace_export_download (raw tar.gz stream — basename-only traversal guard under
 *     the bundles dir, mirrors DiagnosticsHandler::rawBundleDownload),
 *     workspace_import (reuses upload-then-import pattern: accepts uploaded file path from
 *     a prior save_file action, validates, imports, returns applied/skipped summary).
 *     Kept separate from UtilityHandler because UtilityHandler already exceeds its 150-line
 *     constraint and bundle logic is a self-contained domain.</description>
 *     <dependencies>WorkspaceBundleService, ValidationService</dependencies>
 *     <constraints>No secret values in any log line or response. Path-traversal guarded on
 *     both export download and import upload path. Bundle failures NEVER throw into the
 *     AJAX layer — every action catches and returns status=error.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\WorkspaceBundleService;
use AICliAgents\Services\ValidationService;

class BundleHandler
{
    public static function handle($action, $id)
    {
        switch ($action) {
            case 'workspace_export':          return self::export();
            case 'workspace_import':          return self::import();
            case 'workspace_export_download': return null; // raw stream — dispatched via rawBundleDownload()
            default:                          return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['workspace_export', 'workspace_import', 'workspace_export_download'];
    }

    // ---------- Export (JSON response) ----------

    private static function export(): array
    {
        try {
            $rawPath    = $_POST['path'] ?? '';
            $agentId    = $_POST['agentId'] ?? '';
            $inclSec    = !empty($_POST['include_secrets']) && $_POST['include_secrets'] !== '0' && $_POST['include_secrets'] !== 'false';

            $path = ValidationService::validatePath($rawPath);
            if ($path === false || empty($path)) {
                return ['status' => 'error', 'message' => 'Invalid or missing workspace path'];
            }
            $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string)$agentId, 0, 64));
            if (empty($agentId)) {
                return ['status' => 'error', 'message' => 'Missing agentId'];
            }

            return WorkspaceBundleService::export($path, $agentId, $inclSec);

        } catch (\Throwable $e) {
            aicli_log('workspace_export failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'BundleHandler');
            return ['status' => 'error', 'message' => 'Export failed: ' . $e->getMessage()];
        }
    }

    // ---------- Import (JSON response) ----------

    private static function import(): array
    {
        try {
            // The uploaded tar.gz must have been saved via save_file → its path is
            // returned as a validated path under the upload target dir. Here we receive
            // the absolute path of the already-saved file (clients pass the dir+filename).
            $rawUploadDir  = $_POST['upload_dir'] ?? '';
            $rawFilename   = $_POST['filename'] ?? '';
            $rawTargetPath = $_POST['target_path'] ?? '';
            $rawAgentId    = $_POST['agentId'] ?? '';
            $acceptSecrets = !empty($_POST['accept_secrets']) && $_POST['accept_secrets'] !== '0' && $_POST['accept_secrets'] !== 'false';

            // Validate the upload dir (must be under a known tmp location).
            $uploadDir = realpath($rawUploadDir);
            if ($uploadDir === false || !is_dir($uploadDir)) {
                return ['status' => 'error', 'message' => 'Invalid upload directory'];
            }
            // Confine to /tmp — uploaded bundle files live there temporarily.
            if (strpos($uploadDir, '/tmp/') !== 0) {
                return ['status' => 'error', 'message' => 'Upload directory must be under /tmp'];
            }

            // Sanitize filename: no path separators, must end in .tar.gz.
            $filename = ValidationService::sanitizeFilename($rawFilename);
            if (empty($filename) || !preg_match('/\.tar\.gz$/', $filename)) {
                return ['status' => 'error', 'message' => 'Invalid bundle filename (must end in .tar.gz)'];
            }

            $tarPath = $uploadDir . '/' . $filename;
            if (!is_file($tarPath)) {
                return ['status' => 'error', 'message' => 'Uploaded bundle file not found'];
            }

            // Validate target workspace path.
            $targetPath = ValidationService::validatePath($rawTargetPath);
            if ($targetPath === false || empty($targetPath)) {
                return ['status' => 'error', 'message' => 'Invalid or missing target workspace path'];
            }

            $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string)$rawAgentId, 0, 64));
            if (empty($agentId)) {
                return ['status' => 'error', 'message' => 'Missing agentId'];
            }

            $result = WorkspaceBundleService::import($tarPath, $targetPath, $agentId, $acceptSecrets);

            // Clean up the uploaded tar.gz after import (regardless of outcome).
            @unlink($tarPath);

            return $result;

        } catch (\Throwable $e) {
            aicli_log('workspace_import failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'BundleHandler');
            return ['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()];
        }
    }

    // ---------- Raw tar.gz stream (for workspace_export_download) ----------

    /**
     * Path-traversal guard for the download action (pure — unit-testable).
     * Accepts ONLY a basename matching the bundle naming scheme. Mirrors
     * DiagnosticsHandler::safeBundleName.
     */
    public static function safeBundleName(string $raw): ?string
    {
        return WorkspaceBundleService::safeBundleName($raw);
    }

    /**
     * Raw tar.gz stream for workspace_export_download. Called directly by the
     * dispatcher (not through handle()) — same pattern as DiagnosticsHandler::rawBundleDownload.
     */
    public static function rawBundleDownload(): void
    {
        $name = self::safeBundleName($_GET['file'] ?? '');
        if ($name === null) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'invalid bundle name']);
            return;
        }
        $path = rtrim(WorkspaceBundleService::BUNDLE_DIR, '/') . '/' . $name;
        if (!is_file($path)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'bundle not found (create it first)']);
            return;
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)(@filesize($path) ?: 0));
        header('Cache-Control: no-store');
        readfile($path);
    }
}
