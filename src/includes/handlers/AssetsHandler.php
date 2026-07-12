<?php
/**
 * <module_context>
 *     <name>AssetsHandler</name>
 *     <description>#43 (docs/specs/WORKSPACE_ASSET_TREE.md): exposes the
 *     get_agent_config_surface AJAX action — the tagged tree of an agent's
 *     effective config surface (instructions/skills/commands/hooks/mcp/settings/
 *     state/logs) across Global/Project/Ancestor scopes. Also exposes read_file,
 *     a read-only allowlisted file reader for the in-browser config-surface
 *     editor. read_file's optional `allow_missing` POST flag (D-XXX, "+ Add
 *     &lt;file&gt;" create-on-save) lets a caller probe an allowlisted-but-absent
 *     path without the usual no-oracle error: given `allow_missing=1` (any
 *     truthy value per FILTER_VALIDATE_BOOLEAN) and a path that passes
 *     ValidationService::validatePath but does not exist on disk, the response
 *     is `{status:'ok', path, filename, content:'', size:0, truncated:false,
 *     exists:false}` instead of an error — the CodeMirror editor opens on an
 *     empty buffer and `save_file` (UtilityHandler::saveFile) creates the file
 *     on first Save. A path outside the allowlist is STILL a generic error
 *     with `allow_missing` set (no oracle survives the flag). The normal
 *     (existing-file) success response now also carries `exists:true`.
 *     Without `allow_missing`, behaviour is unchanged: a missing file is the
 *     same generic "File not found or access denied" error as an
 *     outside-allowlist path. Thin dispatcher shim over
 *     AssetSurfaceService/ValidationService; read-only (never writes).</description>
 *     <dependencies>AssetSurfaceService, ValidationService</dependencies>
 *     <constraints>Follows the ActivityHandler/UtilityHandler registration shape
 *     in AICliAjax.php. No writes.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\AssetSurfaceService;
use AICliAgents\Services\ValidationService;

class AssetsHandler {

    /** Cap on bytes read by read_file — larger files are truncated, not refused. */
    const MAX_READ_BYTES = 5 * 1024 * 1024;

    public static function handle($action, $id) {
        switch ($action) {
            case 'get_agent_config_surface': return self::getAgentConfigSurface();
            case 'read_file':                return self::readFile();
            default: return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['get_agent_config_surface', 'read_file'];
    }

    /**
     * POST agentId (required), path (optional — empty/absent => GLOBAL scope
     * only, for the Manager panel). See AssetSurfaceService::getSurface() for
     * the response shape.
     */
    private static function getAgentConfigSurface() {
        $agentId = (string)($_POST['agentId'] ?? $_GET['agentId'] ?? '');
        $path    = (string)($_POST['path']    ?? $_GET['path']    ?? '');
        if ($agentId === '') {
            return ['status' => 'error', 'message' => 'agentId is required'];
        }
        try {
            return AssetSurfaceService::getSurface($agentId, $path);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'failed to resolve config surface: ' . $e->getMessage()];
        }
    }

    /**
     * Read-only, allowlist-bounded file reader backing the in-browser config
     * editor. Same no-oracle discipline as UtilityHandler::checkPath(): a path
     * outside ValidationService's allowlist, a missing file, and a directory
     * all produce the same generic error — never distinguishable from one
     * another, UNLESS the caller opts into `allow_missing` (see class
     * docblock): then an allowlisted-but-absent path returns an ok/empty-
     * content response instead of an error — a path outside the allowlist is
     * STILL a generic error even with `allow_missing` set (the flag only
     * waives the missing-file half of the no-oracle rule, never the allowlist
     * half). content is base64 (binary-safe). Files over MAX_READ_BYTES are
     * truncated (not refused) so the editor can still show a useful preview.
     */
    private static function readFile() {
        $rawPath = (string)($_POST['path'] ?? $_GET['path'] ?? '');
        if ($rawPath === '' || strlen($rawPath) > 4096) {
            return ['status' => 'error', 'message' => 'Missing or invalid path'];
        }
        $allowMissing = filter_var($_POST['allow_missing'] ?? $_GET['allow_missing'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $resolved = ValidationService::validatePath($rawPath);
        if ($resolved === false) {
            return ['status' => 'error', 'message' => 'File not found or access denied'];
        }

        $exists = file_exists($resolved);
        if ($allowMissing && !$exists) {
            return [
                'status'    => 'ok',
                'path'      => $resolved,
                'filename'  => basename($resolved),
                'content'   => '',
                'size'      => 0,
                'truncated' => false,
                'exists'    => false,
            ];
        }

        if (!$exists || !is_file($resolved) || !is_readable($resolved)) {
            return ['status' => 'error', 'message' => 'File not found or access denied'];
        }

        $size = @filesize($resolved);
        $truncated = false;
        if ($size !== false && $size > self::MAX_READ_BYTES) {
            $fh = @fopen($resolved, 'rb');
            if ($fh === false) {
                return ['status' => 'error', 'message' => 'Failed to read file'];
            }
            $bytes = fread($fh, self::MAX_READ_BYTES);
            fclose($fh);
            if ($bytes === false) {
                return ['status' => 'error', 'message' => 'Failed to read file'];
            }
            $truncated = true;
        } else {
            $bytes = @file_get_contents($resolved);
            if ($bytes === false) {
                return ['status' => 'error', 'message' => 'Failed to read file'];
            }
        }

        return [
            'status'    => 'ok',
            'path'      => $resolved,
            'filename'  => basename($resolved),
            'content'   => base64_encode($bytes),
            'size'      => strlen($bytes),
            'truncated' => $truncated,
            'exists'    => true,
        ];
    }
}
