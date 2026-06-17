<?php
/**
 * <module_context>
 *     <name>DiagnosticsHandler</name>
 *     <description>AJAX actions for the diagnostics/support surface (R-08, Feature #1371):
 *     diag_bundle_create (build redacted zip, returns basename+size), diag_bundle_download
 *     (raw stream — dispatched directly like rawInstallStatus, basename-only path-traversal
 *     guard), diag_summary (≤3KB redacted markdown|bbcode), diag_known_issues (explicit-action
 *     fetch + server-side signature match — never automatic), health_status (R-09 #1372 —
 *     cached HealthService result, recompute only when stale; drives the header chip).</description>
 *     <dependencies>DiagnosticsService, RedactionService, HealthService</dependencies>
 *     <constraints>Bundle build failures NEVER throw into the AJAX layer — every action catches
 *     and returns status=error. No secret values in any log line or response message.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

class DiagnosticsHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'diag_bundle_create': return self::bundleCreate();
            case 'diag_summary':       return self::summary();
            case 'diag_known_issues':  return self::knownIssues();
            case 'health_status':      return self::healthStatus();
            case 'diag_bundle_download': return null; // raw stream — dispatched via rawBundleDownload()
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['diag_bundle_create', 'diag_bundle_download', 'diag_summary', 'diag_known_issues', 'health_status'];
    }

    /**
     * R-09 (#1372): cached health result for the header status chip + Debug
     * Console. Cheap by construction — HealthService::get() serves the 60s
     * tmpfs cache and recomputes only when stale. Never throws into AJAX.
     */
    private static function healthStatus() {
        try {
            $force = !empty($_REQUEST['refresh']);
            $result = \AICliAgents\Services\HealthService::get($force);
            return array_merge(['status' => 'ok'], $result);
        } catch (\Throwable $e) {
            aicli_log('Health status failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'Diagnostics');
            return ['status' => 'error', 'overall' => 'unknown', 'message' => 'Health status failed: ' . $e->getMessage()];
        }
    }

    private static function bundleCreate() {
        try {
            set_time_limit(120);
            $anonymize = !empty($_REQUEST['anonymize']) && $_REQUEST['anonymize'] !== '0' && $_REQUEST['anonymize'] !== 'false';
            $res = \AICliAgents\Services\DiagnosticsService::createBundle(['anonymize' => $anonymize]);
            return [
                'status'   => 'ok',
                'file'     => $res['file'],
                'size'     => $res['size'],
                'sections' => $res['sections'],
            ];
        } catch (\Throwable $e) {
            // Fail-closed: a redaction self-test failure lands here — no zip was
            // emitted. The message carries key NAMES at most, never values.
            aicli_log('Support bundle build failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'Diagnostics');
            return ['status' => 'error', 'message' => 'Bundle build failed: ' . $e->getMessage()];
        }
    }

    private static function summary() {
        try {
            $format = ($_REQUEST['format'] ?? 'markdown') === 'bbcode' ? 'bbcode' : 'markdown';
            return [
                'status'   => 'ok',
                'format'   => $format,
                'summary'  => \AICliAgents\Services\DiagnosticsService::summary($format),
                'title'    => '[support] AI CLI Agents v' . \AICliAgents\Services\ConfigService::getVersion(),
                'repo_url' => \AICliAgents\Services\DiagnosticsService::STOREFRONT_REPO_URL,
                'forum_url'=> \AICliAgents\Services\DiagnosticsService::FORUM_URL,
            ];
        } catch (\Throwable $e) {
            aicli_log('Diag summary failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'Diagnostics');
            return ['status' => 'error', 'message' => 'Summary failed: ' . $e->getMessage()];
        }
    }

    private static function knownIssues() {
        try {
            set_time_limit(30);
            $force = !empty($_REQUEST['refresh']);
            return \AICliAgents\Services\DiagnosticsService::knownIssues($force);
        } catch (\Throwable $e) {
            aicli_log('Known-issues check failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'Diagnostics');
            return ['status' => 'error', 'message' => 'Known-issues check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Path-traversal guard for the download action (pure — unit-tested).
     * Accepts ONLY a basename matching the bundle naming scheme; anything with
     * a path separator, parent ref, or foreign name is rejected. Mirrors the
     * rawInstallStatus pattern: validate the request token shape, then build
     * the path from a fixed directory + the validated basename.
     */
    public static function safeBundleName($raw): ?string {
        $raw = (string)$raw;
        if ($raw === '' || basename($raw) !== $raw) return null;
        if (!preg_match('/^aicli-support-[A-Za-z0-9._-]+\.zip$/', $raw)) return null;
        if (strpos($raw, '..') !== false) return null;
        return $raw;
    }

    /**
     * Raw zip stream for diag_bundle_download. Called directly by the
     * dispatcher (not through handle()) — same pattern as rawInstallStatus.
     */
    public static function rawBundleDownload() {
        $name = self::safeBundleName($_GET['file'] ?? '');
        if ($name === null) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'invalid bundle name']);
            return;
        }
        $path = rtrim(\AICliAgents\Services\DiagnosticsService::supportDir(), '/') . '/' . $name;
        if (!is_file($path)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'bundle not found (create it first)']);
            return;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)(@filesize($path) ?: 0));
        header('Cache-Control: no-store');
        readfile($path);
    }
}
