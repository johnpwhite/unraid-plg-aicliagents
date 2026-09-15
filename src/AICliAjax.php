<?php
/**
 * <module_context>
 *     <name>AICliAjax</name>
 *     <description>Thin AJAX dispatcher routing actions to focused handler classes.</description>
 *     <dependencies>AICliAgentsManager, ValidationService, TerminalHandler, StorageHandler, AgentHandler, UtilityHandler</dependencies>
 *     <constraints>Under 100 lines. Shared middleware only: CSRF, error handling, time limits.</constraints>
 * </module_context>
 */
ob_start(); // Absorb any stray output from included files before JSON is emitted
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(30);

// Fatal Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        $msg = "FATAL PHP ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        $logFile = "/tmp/unraid-aicliagents/debug.log";
        @file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'PHP Fatal Error: Check debug.log', 'details' => $msg]);
        }
    }
});

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/ValidationService.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/TerminalHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/StorageHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AgentHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/UtilityHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/TmuxHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/ArgsHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AutoLaunchHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/EnvHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/SshKeyService.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/SshHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/ActivityHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/HubHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/GitHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/DiagnosticsHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/WorkspaceBundleService.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/BundleHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AssetsHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/AgentRelayHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/VoiceHandler.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/handlers/FavouritesHandler.php';
use AICliAgents\Services\ValidationService;

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    // External Relay MCP is bearer-authenticated JSON-RPC, not browser AJAX.
    // It must bypass CSRF only for this one dedicated endpoint.
    if ($action === 'relay_http_mcp') {
        ob_end_clean();
        require '/usr/local/emhttp/plugins/unraid-aicliagents/src/AICliRelayMcp.page';
        exit;
    }
    if ($action !== 'filetree') {
        header('Content-Type: application/json');
    }

    // WORKSPACE_UPLOAD_MULTI_CHUNKED.md R5: PHP silently drops a POST body
    // bigger than post_max_size and empties $_POST — that would otherwise
    // fail the CSRF check below and hide the real cause behind "Invalid CSRF
    // Token". Catch it here, before CSRF, for the two upload actions only.
    if (($action === 'save_file' || $action === 'save_file_chunk')
        && !empty($_SERVER['CONTENT_LENGTH']) && empty($_POST)) {
        $postMaxBytes = \AICliAgents\Handlers\UtilityHandler::getUploadLimits()['post_max_bytes'];
        $contentLength = (int) $_SERVER['CONTENT_LENGTH'];
        if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            $limitMb = round($postMaxBytes / 1048576, 1);
            aicli_log("Upload overflow: Content-Length $contentLength exceeds post_max_size $postMaxBytes bytes (action=$action)", AICLI_LOG_ERROR, "AICliAjax");
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => "This upload is too large for one request. The server accepts up to {$limitMb} MB per request (post_max_size). Retry — the overlay should split large files into smaller chunks."]);
            exit;
        }
    }

    // Validate session ID
    $rawId = $_GET['id'] ?? 'default';
    $id = ValidationService::validateId($rawId) ?: 'default';

    // S4 (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S4): a short, explicit list of
    // read-only actions that a browser fetches as a raw byte stream, not JSON —
    // for example an <audio src> for a voice clip. Such a request cannot carry a
    // CSRF token (the tag has no way to add a header or a form field), and it is
    // safe to exempt: the resource id is unguessable and expiring, and the
    // session cookie still applies. A write action must NEVER appear here.
    // A `const` cannot be declared inside an `if` block in PHP, so this uses
    // `define()` instead — functionally the same fixed, explicit list.
    define('CSRF_EXEMPT_READS', ['voice_clip']);

    // CSRF Validation — see PLUGIN_STANDARDS.md Lesson 3.
    // local_prepend.php (auto_prepend_file) validates CSRF from $_POST or X-CSRF-TOKEN
    // header for POST requests, then unsets them. We re-check here from $_REQUEST
    // (which retains the GET copy) so GET-based callers also pass.
    if (!in_array($action, CSRF_EXEMPT_READS, true)) {
        $var = @parse_ini_file("/var/local/emhttp/var.ini");
        $expected = trim((string)($var['csrf_token'] ?? ''));
        $received = $_REQUEST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_array($received)) $received = end($received);
        $received = trim((string)$received);

        if (empty($expected) || $received !== $expected) {
            // S10: log only the action and whether a token arrived. Printing the
            // expected or received token would put a live secret in the log.
            $gotToken = ($received !== '') ? 'yes' : 'no';
            aicli_log("CSRF check failed for action: $action (token received: $gotToken)", AICLI_LOG_ERROR, "AICliAjax");
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF Token']);
            exit;
        }
    }

    // R-06 trace correlation: adopt the client's X-Aicli-Trace header (or the
    // `trace` query param — the React ajaxUrl() builder can't set headers), else
    // generate a fresh 8-hex id. Format-validated by TraceContext::setId, so a
    // hostile value can never reach a log line or a shell env prefix. Every
    // aicli_log line below (including "Handling AJAX Request") carries [t:<id>].
    $rawTrace = (string)($_SERVER['HTTP_X_AICLI_TRACE'] ?? ($_REQUEST['trace'] ?? ''));
    if (!\AICliAgents\Services\TraceContext::setId($rawTrace)) {
        \AICliAgents\Services\TraceContext::setId(\AICliAgents\Services\TraceContext::generate());
    }

    try {
        // Log milestone actions at INFO, everything else at DEBUG.
        // The workspace-menu actions are milestones too: they are things a PERSON
        // did, and at DEBUG they were invisible — on 2026-09-11 a continue arrived
        // that the operator did not expect, and the request that sent it left no
        // line at all at the default log level. continue_session additionally
        // records who asked (TerminalHandler::recordContinue).
        $milestones = ['start', 'stop', 'restart', 'install_agent', 'uninstall_agent', 'consolidate_storage', 'persist_home', 'repair_agent_storage', 'repair_home_storage', 'save', 'wipe_storage',
            'continue_session', 'graceful_close', 'restart_fresh', 'reload_onto_current', 'refresh_bridge', 'workspace_export'];
        $logLvl = in_array($action, $milestones) ? AICLI_LOG_INFO : AICLI_LOG_DEBUG;
        aicli_log("Handling AJAX Request: $action ($id)", $logLvl, "AICliAjax");

        // D-405: Increase limits for file upload actions (base64 POST data can be large).
        // VOICE_INPUT.md R2: voice_transcribe posts a base64 clip up to 4 MiB
        // decoded (~5.4 MB base64) and its own engine call can take up to 30s,
        // so it shares this same allowance.
        if (in_array($action, ['save_file', 'save_file_chunk', 'save_pasted_image', 'voice_transcribe'])) {
            @ini_set('post_max_size', '64M');
            @ini_set('upload_max_filesize', '64M');
            set_time_limit(120);
        }

        // Initialize environment (skip mount for non-mount actions)
        $mustMountActions = ['start', 'restart', 'install_agent', 'uninstall_agent', 'expand_storage', 'shrink_storage', 'migrate_agents'];
        aicli_ensure_init(!in_array($action, $mustMountActions));

        // Route to handler - raw output actions first
        if ($action === 'filetree') {
            ob_end_clean();
            \AICliAgents\Handlers\UtilityHandler::rawFiletree();
        } elseif ($action === 'get_install_status') {
            ob_end_clean();
            \AICliAgents\Handlers\AgentHandler::rawInstallStatus();
        } elseif ($action === 'diag_bundle_download') {
            // R-08: raw zip stream (path-traversal guarded basename under the
            // support dir — see DiagnosticsHandler::safeBundleName).
            ob_end_clean();
            \AICliAgents\Handlers\DiagnosticsHandler::rawBundleDownload();
        } elseif ($action === 'workspace_export_download') {
            // T-11: raw tar.gz stream (path-traversal guarded basename under
            // the bundles dir — see BundleHandler::safeBundleName).
            ob_end_clean();
            \AICliAgents\Handlers\BundleHandler::rawBundleDownload();
        } elseif ($action === 'voice_clip') {
            // AGENT_VOICE.md: raw mp3 stream (id shape-validated in the handler
            // against /^v_[0-9a-f]{16}$/ before it ever reaches a path).
            ob_end_clean();
            \AICliAgents\Handlers\VoiceHandler::rawVoiceClip();
        } elseif ($action === 'get_task_status') {
            // Task status file is already JSON - read and output directly.
            // SECURITY: $user gets interpolated into a filesystem path so
            // validate before use. Accept only POSIX-ish usernames or the
            // literal "agents" sentinel.
            $user = $_GET['user'] ?? '';
            if (empty($user)) {
                $type = $_GET['type'] ?? 'agents';
                $user = ($type === 'agents') ? 'agents' : getAICliConfig()['user'];
            }
            ob_end_clean();
            if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', (string)$user)) {
                header('Content-Type: application/json');
                echo json_encode(['progress' => 0, 'step' => 'invalid user']);
            } else {
                $file = "/tmp/unraid-aicliagents/task-status-$user";
                header('Content-Type: application/json');
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                if (file_exists($file)) echo file_get_contents($file);
                else echo json_encode(['progress' => 0, 'step' => 'Starting...']);
            }
        } else {
            // Standard JSON handlers
            $result = \AICliAgents\Handlers\TerminalHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\StorageHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AgentHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\TmuxHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\ArgsHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AutoLaunchHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\EnvHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\SshHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\ActivityHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\HubHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\GitHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\DiagnosticsHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\BundleHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AssetsHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\AgentRelayHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\VoiceHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\FavouritesHandler::handle($action, $id)
                   ?? \AICliAgents\Handlers\UtilityHandler::handle($action, $id);

            ob_end_clean();
            if ($result !== null) {
                // EVENT_FIRST_RECONCILIATION.md R4/R5: every reconcile read needs
                // a server-clock `ts` so a subscriber can drop a push message
                // older than the last snapshot it applied. This is the one place
                // every standard handler's JSON answer passes through, so stamp
                // it here instead of in each handler. A handler that already set
                // its own `ts` keeps it.
                if (is_array($result) && !array_key_exists('ts', $result)) {
                    $result['ts'] = \AICliAgents\Services\NchanService::nowMs();
                }
                // json_encode output served as application/json — no browser
                // HTML parser involved. Not a user-controlled HTML sink.
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode($result);
            } else {
                $safeAction = preg_replace('/[^a-zA-Z0-9_.-]/', '', substr((string)$action, 0, 64));
                aicli_log("Unknown AJAX action: $safeAction", AICLI_LOG_WARN, 'AICliAjax');
                // Same: JSON response, not HTML. $action has already reached
                // the dispatcher's switch without matching a handler.
                // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
                echo json_encode(['status' => 'error', 'message' => "Unknown action: $action"]);
            }
        }
    } catch (\Throwable $e) {
        aicli_log("AJAX EXCEPTION [$action]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), AICLI_LOG_ERROR);
        $config = getAICliConfig();
        $response = ['status' => 'error', 'message' => $e->getMessage()];
        if (($config['log_level'] ?? 2) >= 3) {
            $response['trace'] = $e->getTraceAsString();
        }
        ob_end_clean();
        echo json_encode($response);
    }

    exit;
}
