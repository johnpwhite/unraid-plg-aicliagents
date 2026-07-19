<?php
/**
 * <module_context>
 *     <name>UtilityHandler</name>
 *     <description>Handles utility AJAX actions: config, workspaces, env, filetree, uploads.</description>
 *     <dependencies>AICliAgentsManager, ValidationService, ConfigService</dependencies>
 *     <constraints>Under 150 lines. Each method returns array for JSON encoding (filetree returns HTML).</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\ValidationService;

class UtilityHandler {

    public static function handle($action, $id) {
        switch ($action) {
            case 'debug':            return self::debug();
            case 'save':             return self::save();
            case 'save_vault':       return self::saveVault();
            case 'get_workspaces':   return self::getWorkspaces();
            case 'save_workspaces':  return self::saveWorkspaces();
            case 'get_env':          return self::getEnv();
            case 'save_env':         return self::saveEnv();
            case 'filetree':         return null; // Handled via rawFiletree()
            case 'list_dir':         return self::listDir();
            case 'create_dir':       return self::createDirectory(
                $_POST['parent'] ?? $_GET['parent'] ?? '',
                $_POST['name'] ?? $_GET['name'] ?? ''
            );
            case 'check_path':       return self::checkPath();
            case 'upload_chunk':     return self::uploadChunk();
            case 'save_file':        return self::saveFile();
            case 'save_pasted_image': return self::savePastedImage();
            case 'perf_log':          return self::perfLog();
            case 'log_client_error':  return self::clientError();
            default:                  return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['debug', 'save', 'save_vault', 'get_workspaces', 'save_workspaces', 'get_env', 'save_env',
                'filetree', 'list_dir', 'create_dir', 'check_path', 'upload_chunk', 'save_file', 'save_pasted_image', 'perf_log', 'log_client_error'];
    }

    /**
     * Browser-side perf-tracing sink. Appends a line to /tmp/unraid-aicliagents/perf.log
     * in the same format as the shell's perf_log function so timings correlate by session ID.
     * Strict allowlist on stage names prevents log-injection from a hostile page.
     */
    private static function perfLog() {
        $stage = $_REQUEST['stage'] ?? '';
        $agent = $_REQUEST['agent'] ?? 'unknown';
        $session = $_REQUEST['session'] ?? 'unknown';
        // Allowlist: only known browser-side stage names accepted
        if (!preg_match('/^browser\.[a-z0-9._-]{1,40}$/', $stage)) {
            return ['status' => 'error', 'message' => 'invalid stage'];
        }
        // Sanitize agent + session against the same shell-safe pattern used elsewhere
        $agent = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($agent, 0, 32));
        $session = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($session, 0, 32));
        $ms = (int)floor(microtime(true) * 1000);
        $line = "$ms $stage $agent $session\n";
        @file_put_contents('/tmp/unraid-aicliagents/perf.log', $line, FILE_APPEND);
        return ['status' => 'ok'];
    }

    /**
     * Browser-side error sink. Accepts uncaught JS errors and unhandled promise
     * rejections, logs them via LogService so they appear in the plugin log.
     * Inputs are stripped/truncated to prevent log injection.
     */
    private static function clientError() {
        $type = $_POST['type'] ?? $_REQUEST['type'] ?? '';
        if (!in_array($type, ['uncaught', 'unhandled_rejection'], true)) {
            return ['status' => 'error', 'message' => 'invalid type'];
        }
        $message = substr($_POST['message'] ?? $_REQUEST['message'] ?? '', 0, 500);
        $detail  = substr($_POST['detail']  ?? $_REQUEST['detail']  ?? '', 0, 2000);
        // Strip control characters to prevent log injection
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message);
        $detail  = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $detail);
        \AICliAgents\Services\LogService::log(
            "Client $type: $message | $detail",
            \AICliAgents\Services\LogService::LOG_ERROR,
            'ClientError'
        );
        return ['status' => 'ok'];
    }

    private static function debug() {
        return self::debugPayload();
    }

    /**
     * Compose the boot payload while treating storage diagnostics as optional.
     * Config and the registry must remain available even if a storage status
     * provider throws, otherwise the React application cannot render recovery.
     * The injectable provider is the regression-test seam for issue #39.
     */
    public static function debugPayload(?callable $storageProvider = null): array {
        $storageProvider = $storageProvider ?? static fn() => aicli_get_storage_status();
        $storageStatus = null;
        $storageError = null;
        try {
            $storageStatus = $storageProvider();
        } catch (\Throwable $e) {
            $storageError = 'Storage diagnostics are temporarily unavailable.';
        }
        return [
            'status' => 'ok',
            'config' => getAICliConfig(),
            'registry' => getAICliAgentsRegistry(),
            'storage_status' => $storageStatus,
            'storage_status_error' => $storageError,
        ];
    }

    private static function save() {
        saveAICliConfig($_POST);
        return ['status' => 'ok'];
    }

    /**
     * Persist per-agent schema secrets to /boot/config/plugins/unraid-aicliagents/secrets.cfg.
     * Merges submitted env vars over the existing file (so saving one agent's key doesn't
     * wipe another's). Only environment-variable-looking keys pass through the allowlist.
     * File is written with 0600 so only root can read.
     *
     * Empty-value semantics (WP #736 follow-up): the UI sends every schema field
     * (it omits only fields still showing the masked '••••••••' placeholder, i.e.
     * untouched-and-set). A submitted EMPTY value means "the user cleared this
     * field" → delete the key from the vault. (Previously the JS skipped empty
     * fields entirely, so clearing a secret was a silent no-op.)
     */
    private static function saveVault() {
        $file = '/boot/config/plugins/unraid-aicliagents/secrets.cfg';
        $existing = file_exists($file) ? (@parse_ini_file($file) ?: []) : [];

        $touched = 0;
        $resolved = [];
        // First pass: collect literal values keyed by declared env name (or
        // placeholder-containing name). Literal values (e.g. GOOSE_PROVIDER=anthropic)
        // feed the second pass's placeholder substitution.
        foreach ($_POST as $k => $v) {
            if ($k === 'csrf_token' || $k === 'action' || $k === 'agentId') continue;
            // Accept: uppercase identifier, OR one containing a {PLACEHOLDER} token.
            if (!preg_match('/^\{?[A-Z][A-Z0-9_]*\}?[A-Z0-9_]{0,127}$/', (string)$k)) continue;
            $resolved[$k] = (string)$v;
        }
        // Second pass: resolve {PLACEHOLDER}_API_KEY forms. PLACEHOLDER is itself
        // a key in $resolved (e.g. GOOSE_PROVIDER=anthropic => the resolved env
        // name becomes ANTHROPIC_API_KEY). Missing placeholder = skip that field.
        foreach ($resolved as $k => $v) {
            if (preg_match('/\{([A-Z_][A-Z0-9_]*)\}/', $k, $m)) {
                $placeholder = $m[1];
                $subst = $resolved[$placeholder] ?? '';
                if ($subst === '') continue; // no provider chosen yet — can't resolve the API-key name
                $realEnv = str_replace($m[0], strtoupper($subst), $k);
                if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $realEnv)) continue;
                if ($v === '') {                       // user cleared the field → delete the key
                    if (array_key_exists($realEnv, $existing)) { unset($existing[$realEnv]); $touched++; }
                } else {
                    $existing[$realEnv] = $v; $touched++;
                }
                continue;
            }
            // Final guard: only literal names that pass the strict shape persist.
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $k)) continue;
            if ($v === '') {                           // user cleared the field → delete the key
                if (array_key_exists($k, $existing)) { unset($existing[$k]); $touched++; }
            } else {
                $existing[$k] = $v; $touched++;
            }
        }

        $content = '';
        foreach ($existing as $k => $v) {
            // Double-quoted INI format matches parse_ini_file readers server-side + shell readers
            // in aicli-shell.sh. Escape embedded double-quotes.
            $content .= $k . '="' . addslashes((string)$v) . '"' . PHP_EOL;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $res = @file_put_contents($file, $content);
        if ($res === false) {
            return ['status' => 'error', 'message' => 'Failed to write secrets.cfg'];
        }
        @chmod($file, 0600);
        return ['status' => 'ok', 'updated' => $touched];
    }

    private static function getWorkspaces() {
        return aicli_get_workspaces();
    }

    private static function saveWorkspaces() {
        $data = json_decode($_POST['workspaces'] ?? '[]', true);
        if (is_array($data)) {
            aicli_save_workspaces($data);
            return ['status' => 'ok'];
        }
        return ['status' => 'error', 'message' => 'Invalid Workspace data'];
    }

    private static function getEnv() {
        $path = $_GET['path'] ?? '';
        $agentId = $_GET['agentId'] ?? 'gemini-cli';
        if (empty($path)) {
            return ['status' => 'error', 'message' => 'Workspace path is required'];
        }
        $envs = \AICliAgents\Services\ConfigService::getWorkspaceEnvs($path, $agentId);
        return ['status' => 'ok', 'envs' => $envs];
    }

    private static function saveEnv() {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        $agentId = $_POST['agentId'] ?? $_GET['agentId'] ?? 'gemini-cli';
        $envs = json_decode($_POST['envs'] ?? $_GET['envs'] ?? '{}', true);
        if (empty($path)) {
            return ['status' => 'error', 'message' => 'Workspace path is required'];
        }
        // D-403: Use the wrapper function which triggers immediate home persistence
        saveWorkspaceEnvs($path, $agentId, $envs);
        return ['status' => 'ok'];
    }

    /** Outputs HTML directly for jqueryFileTree (not JSON). */
    public static function rawFiletree() {
        $rawDir = $_POST['dir'] ?? '/mnt/user/';
        $dir = ValidationService::validatePath($rawDir);
        if ($dir === false) {
            echo "<ul class=\"jqueryFileTree\"><li>Access denied</li></ul>";
            return;
        }
        if (!file_exists($dir)) return;
        $files = @scandir($dir);
        if (!is_array($files)) return;

        natcasesort($files);
        echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">";
        if ($dir !== '/') {
            $up = dirname(rtrim($dir, '/')) . '/';
            echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($up) . "\"><i class=\"fa fa-level-up-alt\" style=\"margin-right:8px; opacity:0.6;\"></i>..</a></li>";
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = rtrim($dir, '/') . '/' . $file;
            if (is_dir($full)) {
                echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($full) . "/\">" . htmlentities($file) . "</a></li>";
            }
        }
        echo "</ul>";
    }

    private static function listDir() {
        $rawPath = $_GET['path'] ?? '/mnt';
        // Resolve canonical path (prevent traversal) but allow browsing anywhere readable
        $path = realpath($rawPath);
        if ($path === false || !is_dir($path) || !is_readable($path)) {
            return ['status' => 'error', 'message' => 'Path not found or access denied'];
        }
        $items = [];
        if ($path !== '/') $items[] = ['name' => '..', 'path' => dirname($path)];
        $files = @scandir($path);
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $full = rtrim($path, '/') . '/' . $file;
                // Only show directories the user can actually read
                if (is_dir($full) && is_readable($full)) {
                    $items[] = ['name' => $file, 'path' => $full];
                }
            }
        }
        return ['status' => 'ok', 'path' => $path, 'items' => $items];
    }

    /**
     * Create one child directory for the workspace browser.
     *
     * The optional base list is a test seam; production callers use
     * ValidationService's standard filesystem allowlist.
     */
    public static function createDirectory($rawParent, $rawName, ?array $allowedBases = null): array {
        if (!is_string($rawParent) || !is_string($rawName)) {
            return ['status' => 'error', 'message' => 'Folder parent and name must be text.'];
        }

        $parent = ValidationService::validatePath($rawParent, $allowedBases);
        if ($parent === false || !is_dir($parent)) {
            return ['status' => 'error', 'message' => 'Parent folder was not found or is not allowed.'];
        }

        $name = trim($rawName);
        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F\\/\\\\]/', $name)) {
            return ['status' => 'error', 'message' => 'Enter a valid single folder name (maximum 255 bytes).'];
        }

        if (!is_writable($parent)) {
            return ['status' => 'error', 'message' => 'The selected parent folder is not writable.'];
        }

        $destination = rtrim($parent, '/') . '/' . $name;
        if (file_exists($destination) || is_link($destination)) {
            return ['status' => 'error', 'message' => 'A file or folder with that name already exists.'];
        }

        error_clear_last();
        if (!@mkdir($destination, 0777, false)) {
            $phpError = error_get_last();
            $detail = $phpError !== null ? ': ' . $phpError['message'] : '';
            aicli_log("Folder creation failed under $parent$detail", AICLI_LOG_ERROR, 'UtilityHandler');
            return ['status' => 'error', 'message' => 'The folder could not be created. Check the parent folder permissions.'];
        }

        $created = realpath($destination);
        if ($created === false || dirname($created) !== $parent) {
            aicli_log("Folder was created but canonical verification failed under $parent", AICLI_LOG_ERROR, 'UtilityHandler');
            return ['status' => 'error', 'message' => 'The folder was created but could not be verified safely.'];
        }

        aicli_log("Workspace folder created: $created", AICLI_LOG_INFO, 'UtilityHandler');
        return ['status' => 'ok', 'path' => $created];
    }

    private static function uploadChunk() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? '';
        $chunkIndex = (int)($_POST['chunkIndex'] ?? 0);
        $totalChunks = (int)($_POST['totalChunks'] ?? 1);
        $chunk = $_FILES['chunk'] ?? null;

        aicli_log("[Upload] Chunk $chunkIndex/$totalChunks for '$rawFilename' to '$rawPath'" .
                  ($chunk ? " (size: " . ($chunk['size'] ?? '?') . ", error: " . ($chunk['error'] ?? '?') . ")" : " (NO FILE DATA)"),
                  AICLI_LOG_DEBUG, "UtilityHandler");

        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);

        if (!$chunk) {
            aicli_log("[Upload] REJECTED: No chunk file in \$_FILES. Keys: " . implode(',', array_keys($_FILES)), AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'No file data received. Check upload_max_filesize in PHP.'];
        }
        if ($chunk['error'] !== UPLOAD_ERR_OK) {
            $errors = [1=>'upload_max_filesize exceeded', 2=>'MAX_FILE_SIZE exceeded', 3=>'Partial upload', 4=>'No file uploaded', 6=>'Missing temp dir', 7=>'Disk write failed'];
            $errMsg = $errors[$chunk['error']] ?? "Unknown error code {$chunk['error']}";
            aicli_log("[Upload] REJECTED: PHP upload error: $errMsg", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => "PHP upload error: $errMsg"];
        }
        if ($targetPath === false) {
            aicli_log("[Upload] REJECTED: Path validation failed for '$rawPath'", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Path validation failed: ' . $rawPath];
        }
        if (empty($filename)) {
            aicli_log("[Upload] REJECTED: Filename empty after sanitization (raw: '$rawFilename')", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }

        if (!\AICliAgents\Services\StorageMountService::isBackingMountAvailable($targetPath)) {
            return ['status' => 'error', 'message' => 'Target storage is not mounted'];
        }

        if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        $mode = ($chunkIndex == 0) ? 'wb' : 'ab';
        $fp = fopen($dest, $mode);
        if ($fp) {
            $bytes = fwrite($fp, file_get_contents($chunk['tmp_name']));
            fclose($fp);
            aicli_log("[Upload] Chunk $chunkIndex written: $bytes bytes to $dest (mode: $mode)", AICLI_LOG_DEBUG, "UtilityHandler");
            if ($chunkIndex + 1 >= $totalChunks) {
                $finalSize = filesize($dest);
                aicli_log("[Upload] Complete: $filename ($finalSize bytes) saved to $targetPath", AICLI_LOG_INFO, "UtilityHandler");
            }
            return ['status' => 'ok'];
        }
        aicli_log("[Upload] FAILED: Could not open $dest for writing", AICLI_LOG_ERROR, "UtilityHandler");
        return ['status' => 'error', 'message' => 'Failed to write to ' . $dest];
    }

    /**
     * #40 (docs/specs/TMUX_PATH_LINKS.md): read-only existence check for a
     * terminal path-link candidate. The path rides in the POST body (never the
     * query string → never nginx access logs). validatePath() canonicalises and
     * enforces the allowlisted bases; anything outside them reports exists=false
     * rather than leaking whether the path is real.
     */
    private static function checkPath() {
        $rawPath = $_POST['path'] ?? '';
        if (!is_string($rawPath) || $rawPath === '' || strlen($rawPath) > 4096) {
            return ['status' => 'error', 'message' => 'Missing or invalid path'];
        }
        $resolved = ValidationService::validatePath($rawPath);
        if ($resolved === false) {
            return ['status' => 'ok', 'exists' => false, 'isFile' => false, 'path' => ''];
        }
        $exists = file_exists($resolved);
        return [
            'status' => 'ok',
            'exists' => $exists,
            'isFile' => $exists && is_file($resolved),
            'path'   => $exists ? $resolved : '',
        ];
    }

    /**
     * D-405: Save a file from base64-encoded POST data (avoids multipart which hangs on Unraid nginx).
     */
    private static function saveFile() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? '';
        $b64data = $_POST['filedata'] ?? '';

        aicli_log("[Upload/SaveFile] Received: '$rawFilename' to '$rawPath' (" . strlen($b64data) . " b64 chars)", AICLI_LOG_DEBUG, "UtilityHandler");

        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);

        if ($targetPath === false) {
            aicli_log("[Upload/SaveFile] REJECTED: Path validation failed for '$rawPath'", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Path validation failed: ' . $rawPath];
        }
        if (empty($filename)) {
            aicli_log("[Upload/SaveFile] REJECTED: Empty filename after sanitization", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }
        if (empty($b64data)) {
            aicli_log("[Upload/SaveFile] REJECTED: No file data received", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'No file data received'];
        }

        if (!\AICliAgents\Services\StorageMountService::isBackingMountAvailable($targetPath)) {
            return ['status' => 'error', 'message' => 'Target storage is not mounted'];
        }

        $data = base64_decode($b64data, true);
        if ($data === false) {
            aicli_log("[Upload/SaveFile] REJECTED: base64_decode failed", AICLI_LOG_ERROR, "UtilityHandler");
            return ['status' => 'error', 'message' => 'Invalid base64 data'];
        }

        if (!is_dir($targetPath)) @mkdir($targetPath, 0777, true);
        // Ensure writable — user share dirs created by root (mode 755) may be
        // unwritable for the nobody:users PHP process. chmod is a best-effort
        // attempt; if the caller is nobody and doesn't own the dir it silently
        // no-ops, but for world-writable shares it works.
        if (!is_writable($targetPath)) @chmod($targetPath, 0777);

        // $targetPath is validated by ValidationService::validatePath (whitelisted
        // bases, rejects ../ and prefix-impersonation). $filename is sanitised by
        // ValidationService::sanitizeFilename. Destination cannot escape the
        // allowlisted bases.
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        error_clear_last();
        // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        $bytes = @file_put_contents($dest, $data);
        if ($bytes !== false) {
            aicli_log("[Upload/SaveFile] Complete: $filename ($bytes bytes) saved to $targetPath", AICLI_LOG_INFO, "UtilityHandler");
            return ['status' => 'ok', 'filename' => $filename, 'bytes' => $bytes];
        }
        $phpErr = error_get_last();
        $errDetail = $phpErr ? $phpErr['message'] : 'unknown error';
        aicli_log("[Upload/SaveFile] FAILED: Could not write to $dest — $errDetail", AICLI_LOG_ERROR, "UtilityHandler");
        return ['status' => 'error', 'message' => 'Failed to write file to ' . $dest . ' (' . $errDetail . ')'];
    }

    private static function savePastedImage() {
        $rawPath = $_POST['path'] ?? '';
        $rawFilename = $_POST['filename'] ?? 'pasted_image_' . time() . '.png';
        $data = $_POST['data'] ?? '';
        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);
        if (empty($data) || $targetPath === false || empty($filename)) {
            return ['status' => 'error', 'message' => 'Missing image data or invalid path'];
        }
        if (!\AICliAgents\Services\StorageMountService::isBackingMountAvailable($targetPath)) {
            return ['status' => 'error', 'message' => 'Target storage is not mounted'];
        }
        if (!preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            return ['status' => 'error', 'message' => 'Invalid image format'];
        }
        $data = substr($data, strpos($data, ',') + 1);
        // base64_decode in non-strict mode returns '' for invalid input (not
        // false), so the empty-string check catches all failure modes.
        $data = base64_decode($data);
        if ($data === '') {
            return ['status' => 'error', 'message' => 'base64_decode failed'];
        }
        if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        // Same protection as saveFile() above: ValidationService::validatePath +
        // sanitizeFilename ran at lines 328-329. Destination can't escape the
        // allowlisted base.
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        if (@file_put_contents($dest, $data)) {
            return ['status' => 'ok', 'filename' => $filename];
        }
        return ['status' => 'error', 'message' => 'Failed to save image'];
    }
}
