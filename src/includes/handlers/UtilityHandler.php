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
            case 'picker_create_folder': return self::createPickerFolder(
                $_POST['parent'] ?? $_GET['parent'] ?? '',
                $_POST['name'] ?? $_GET['name'] ?? '',
                filter_var($_POST['allow_hidden'] ?? $_GET['allow_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
            case 'check_path':       return self::checkPath();
            case 'save_file':        return self::saveFile(
                $_POST['path'] ?? '',
                $_POST['filename'] ?? '',
                $_POST['filedata'] ?? '',
                filter_var($_POST['secret'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
            case 'save_file_chunk':  return self::saveFileChunk(
                $_POST['path'] ?? '',
                $_POST['filename'] ?? '',
                $_POST['filedata'] ?? '',
                (string)($_POST['uploadId'] ?? ''),
                (int)($_POST['chunkIndex'] ?? -1),
                (int)($_POST['totalChunks'] ?? 0),
                filter_var($_POST['secret'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
            case 'get_upload_limits': return self::getUploadLimits();
            case 'save_pasted_image': return self::savePastedImage();
            case 'perf_log':          return self::perfLog();
            case 'log_client_error':  return self::clientError();
            case 'get_secrets_dir':   return self::getSecretsDir();
            default:                  return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions() {
        return ['debug', 'save', 'save_vault', 'get_workspaces', 'save_workspaces', 'get_env', 'save_env',
                'filetree', 'list_dir', 'create_dir', 'picker_create_folder', 'check_path', 'save_file', 'save_file_chunk',
                'get_upload_limits', 'save_pasted_image', 'perf_log', 'log_client_error', 'get_secrets_dir'];
    }

    /**
     * docs/specs/FILE_VIEWER_SECRET_DROP.md R1/R5 — resolve (and, on first
     * use, create) the current workspace user's `.claude/secrets/` directory.
     * The user is the plugin's configured session user (the same one
     * ConfigService::getUserStatePath() and the launch path use), never a
     * value from the request. A missing home mount (array stopped / an
     * emergency session) is reported as `exists:false` — no directory is
     * fabricated under a phantom mount, and the client tells the operator
     * and does nothing further.
     */
    private static function getSecretsDir() {
        $config = \AICliAgents\Services\ConfigService::getConfig();
        $user = (string)($config['user'] ?? 'root');
        if ($user === '') $user = 'root';
        $dir = \AICliAgents\Services\SecretPaths::ensureSecretsDir($user);
        $exists = $dir !== '' && is_dir($dir);
        $normalised = $exists ? \AICliAgents\Services\SecretPaths::normaliseModes($dir) : 0;
        return [
            'status'     => 'ok',
            'path'       => $exists ? $dir : \AICliAgents\Services\SecretPaths::secretsDirForUser($user),
            'exists'     => $exists,
            'normalised' => $normalised,
        ];
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
        // HOME_BACKUP.md: the Manager UI no longer posts backup_* fields here
        // (#287: each home saves its own settings through
        // set_home_backup_setting, and the Storage tab is outside this form
        // since Bug #710). A caller that still posts the global
        // backup_schedule (the seed for homes with no settings of their own)
        // resyncs the cron; an ordinary save never rewrites the cron file.
        if (array_key_exists('backup_schedule', $_POST)) {
            \AICliAgents\Services\BackupCronService::sync(getAICliConfig());
        }
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
        $removedIds = json_decode($_POST['removed_ids'] ?? '[]', true);
        if (is_array($data)) {
            if (!aicli_save_workspaces($data, is_array($removedIds) ? $removedIds : [])) {
                return ['status' => 'error', 'message' => \AICliAgents\Services\ConfigService::lastWorkspaceSaveMessage() ?? 'Could not save workspace state'];
            }
            // #137: a closed (removed) workspace must not linger as a ghost Relay
            // contact — sweep its non-owner relay traces on close.
            if (is_array($removedIds)) {
                foreach ($removedIds as $rid) {
                    if (is_string($rid) && $rid !== '') \AICliAgents\Services\AgentRelayService::forgetSessionRelayTraces($rid);
                }
            }
            // PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md: subscriptions and cursors
            // die with the workspace, the same rule #137 already applies to Relay
            // traces above. Pass every still-live workspace id (not just the ones
            // just removed) so a subscription orphaned by any other path — a crash,
            // a Tier 3 delete — is swept here too the next time anyone saves.
            if (class_exists('\\AICliAgents\\Services\\EventSubscriptionStore')) {
                try {
                    $liveIds = array_column(aicli_get_workspaces()['sessions'] ?? [], 'id');
                    \AICliAgents\Services\EventSubscriptionStore::reapStale($liveIds);
                } catch (\Throwable $e) {
                    // Best-effort — a sweep failure must not affect the save that already succeeded.
                }
            }
            // #128: a recreated workspace gets a new id; re-adopt topic ownership by
            // identity so the owner regains its topic the moment the drawer is saved.
            \AICliAgents\Services\AgentRelayService::reAdoptOwnersByIdentity();
            // Return the authoritative registry. In particular this restores a
            // Relay-managed actor immediately if an old drawer tried to close it.
            return ['status' => 'ok', 'workspaces' => aicli_get_workspaces()];
        }
        return ['status' => 'error', 'message' => 'Invalid Workspace data'];
    }

    private static function getEnv() {
        $path = $_GET['path'] ?? '';
        if (empty($path)) {
            return ['status' => 'error', 'message' => 'Workspace path is required'];
        }
        // This one REFUSES rather than degrades: an empty env map would read as
        // "nothing is set", and the editor could then save that emptiness over the
        // user's real values. The env editor always knows its agent, so an
        // unresolved agent here is a caller fault worth surfacing.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_GET['agentId'] ?? null, (string)($_GET['id'] ?? ''), (string)($_GET['path'] ?? ''));
        if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('get_env', (string)($_GET['id'] ?? ''), (string)($_GET['path'] ?? ''));
        $envs = \AICliAgents\Services\ConfigService::getWorkspaceEnvs($path, $agentId);
        return ['status' => 'ok', 'envs' => $envs];
    }

    private static function saveEnv() {
        $path = $_POST['path'] ?? $_GET['path'] ?? '';
        // Never default the agent: this WRITES env vars, and defaulting saved
        // them into a different agent's file where they silently never applied.
        $agentId = \AICliAgents\Services\ConfigService::resolveAgentId($_POST['agentId'] ?? $_GET['agentId'] ?? null, (string)($_POST['id'] ?? $_GET['id'] ?? ''), (string)($_POST['path'] ?? $_GET['path'] ?? ''));
        if ($agentId === '') return \AICliAgents\Services\ConfigService::agentIdUnresolvedError('save_env', (string)($_POST['id'] ?? $_GET['id'] ?? ''), (string)($_POST['path'] ?? $_GET['path'] ?? ''));
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
        $scanDir = \AICliAgents\Services\PoolPathService::resolvePoolPath($dir) ?? $dir;
        $listing = self::readDirectoryPage($scanDir, $dir, 1, self::DIRECTORY_PAGE_MAX);
        if (($listing['error'] ?? '') !== '') return;

        echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">";
        if ($dir !== '/') {
            $up = dirname(rtrim($dir, '/')) . '/';
            echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($up) . "\"><i class=\"fa fa-level-up-alt\" style=\"margin-right:8px; opacity:0.6;\"></i>..</a></li>";
        }
        foreach ($listing['items'] as $item) {
            echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($item['path']) . "/\">" . htmlentities($item['name']) . "</a></li>";
        }
        if (!empty($listing['has_more'])) {
            echo '<li class="directory"><span>More folders are available in the paged workspace picker.</span></li>';
        }
        echo "</ul>";
    }

    private const DIRECTORY_PAGE_DEFAULT = 50;
    private const DIRECTORY_PAGE_MAX = 100;
    private const DIRECTORY_PAGE_MAX_NUMBER = 10;
    private const DIRECTORY_SCAN_MAX = 1000;

    private static function listDir() {
        $rawPath = $_GET['path'] ?? '/mnt';
        $page = max(1, min(self::DIRECTORY_PAGE_MAX_NUMBER, (int)($_GET['page'] ?? 1)));
        $limit = max(1, min(self::DIRECTORY_PAGE_MAX, (int)($_GET['limit'] ?? self::DIRECTORY_PAGE_DEFAULT)));
        // Resolve canonical path first (prevent traversal), then choose a safe
        // direct pool scan only for a proven cache-only user share. The logical
        // path is retained in the response and in every child item.
        $path = realpath($rawPath);
        if ($path === false || !is_dir($path) || !is_readable($path)) {
            return ['status' => 'error', 'message' => 'Path not found or access denied'];
        }
        if (ValidationService::validatePath($rawPath) === false) {
            return ['status' => 'error', 'message' => 'Path not found or access denied'];
        }
        $scanPath = \AICliAgents\Services\PoolPathService::resolvePoolPath($path) ?? $path;
        $listing = self::readDirectoryPage($scanPath, $path, $page, $limit);
        if (($listing['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => 'Directory is temporarily unavailable'];
        }
        $items = [];
        if ($path !== '/') $items[] = ['name' => '..', 'path' => dirname($path)];
        $items = array_merge($items, $listing['items']);
        $hasMore = (bool)$listing['has_more'] && $page < self::DIRECTORY_PAGE_MAX_NUMBER;
        $response = [
            'status' => 'ok',
            'path' => $path,
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
            'truncated' => !empty($listing['scan_capped']) || ((bool)$listing['has_more'] && !$hasMore),
        ];
        // FILE_VIEWER_SECRET_DROP.md R3: a listing of the secrets directory
        // fixes any loose (e.g. 0775) modes it finds on existing files and
        // reports how many — the files themselves never appear in $items
        // (they are not directories), only the count is surfaced.
        if (\AICliAgents\Services\SecretPaths::isSecretDir($path)) {
            $response['normalised'] = \AICliAgents\Services\SecretPaths::normaliseModes($path);
        }
        return $response;
    }

    /**
     * Read at most DIRECTORY_SCAN_MAX directory entries. This deliberately
     * uses readdir rather than scandir: the picker must never materialise an
     * arbitrarily large directory before returning a bounded page.
     *
     * @return array{items:array<int,array{name:string,path:string}>,has_more:bool,scan_capped:bool,error:string}
     */
    private static function readDirectoryPage(string $scanPath, string $logicalPath, int $page, int $limit): array {
        $handle = @opendir($scanPath);
        if ($handle === false) return ['items' => [], 'has_more' => false, 'scan_capped' => false, 'error' => 'open'];
        $skip = ($page - 1) * $limit;
        $matching = 0;
        $scanned = 0;
        $items = [];
        $hasMore = false;
        $scanCapped = false;
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') continue;
            if (++$scanned > self::DIRECTORY_SCAN_MAX) {
                $hasMore = true;
                $scanCapped = true;
                break;
            }
            $full = rtrim($scanPath, '/') . '/' . $file;
            if (!is_dir($full) || !is_readable($full)) continue;
            if ($matching++ < $skip) continue;
            if (count($items) >= $limit) {
                $hasMore = true;
                break;
            }
            $items[] = ['name' => $file, 'path' => rtrim($logicalPath, '/') . '/' . $file];
        }
        closedir($handle);
        return ['items' => $items, 'has_more' => $hasMore, 'scan_capped' => $scanCapped, 'error' => ''];
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

    /**
     * Roots the folder browser may create a folder under: the browser's own
     * roots (ValidationService) without the flash drive.
     */
    public const PICKER_CREATE_BASES = ['/mnt', '/home', '/root', '/tmp/unraid-aicliagents'];

    /** Folders that only hold mount points: a folder made here would sit in RAM, not on a disk. */
    private const PICKER_MOUNT_HOLDERS = ['/mnt', '/mnt/disks', '/mnt/remotes', '/mnt/addons', '/mnt/rootshare', '/mnt/user', '/mnt/user0'];

    /**
     * The rules for a new folder's name. '' when the name is good, else the
     * reason in plain words. One path segment, 1-64 characters of letters,
     * digits, space and . _ - + @ , ( ) ; no leading dot unless $allowHidden;
     * no leading dash or space; no trailing dot or space.
     */
    public static function pickerFolderNameError(string $name, bool $allowHidden = false): string {
        if ($name === '') return 'Type a name for the new folder.';
        if (strlen($name) > 64) return 'Use a name of 64 characters or fewer.';
        if ($name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return 'Type one folder name, not a path.';
        }
        if ($name[0] === '.' && !$allowHidden) return 'A name that starts with a dot makes a hidden folder. Use another name.';
        if (!preg_match('/^[A-Za-z0-9 ._+@,()-]+$/', $name)) {
            return 'Use only letters, digits, spaces and . _ - + @ , ( ) in the name.';
        }
        if ($name[0] === '-' || $name[0] === ' ') return 'The name cannot start with a dash or a space.';
        $last = substr($name, -1);
        if ($last === '.' || $last === ' ') return 'The name cannot end with a dot or a space.';
        return '';
    }

    /**
     * HOME_BACKUP.md "2026-09-24 follow-up" — the "New folder" action of the
     * shared folder browser (openPathPicker). Makes ONE folder under a parent
     * inside PICKER_CREATE_BASES. A /mnt/user share parent is created on its
     * pool path when the share lives only on a pool (the same rule list_dir and
     * the backup target use); any other /mnt/user or /mnt/user0 parent, and any
     * FUSE parent, is refused, so the browser never writes through the share.
     *
     * @param array|null    $allowedBases test seam (null = PICKER_CREATE_BASES)
     * @param callable|null $fstype       test seam: fn(string $path): string
     * @param callable|null $poolResolver test seam: fn(string $userPath): ?string
     * @return array{status:string,message?:string,path?:string,resolved?:string}
     */
    public static function createPickerFolder($rawParent, $rawName, bool $allowHidden = false,
                                              ?array $allowedBases = null, ?callable $fstype = null,
                                              ?callable $poolResolver = null): array {
        if (!is_string($rawParent) || !is_string($rawName)) {
            return ['status' => 'error', 'message' => 'The folder and the name must be text.'];
        }
        $nameError = self::pickerFolderNameError($rawName, $allowHidden);
        if ($nameError !== '') return ['status' => 'error', 'message' => $nameError];

        $parent = rtrim($rawParent, '/');
        if ($parent === '' || $rawParent[0] !== '/' || strlen($rawParent) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $rawParent) || preg_match('#(^|/)\.{1,2}(/|$)#', $parent)
            || strpos($parent, '//') !== false) {
            return ['status' => 'error', 'message' => 'The folder you are in is not a valid path.'];
        }

        // /mnt/user (FUSE): never write through the share. A cache-only share
        // is created on its pool path; everything else is refused.
        $logicalParent = $parent;
        if (preg_match('#^/mnt/user0?(/|$)#', $parent)) {
            $resolver = $poolResolver ?? static fn(string $p): ?string => \AICliAgents\Services\PoolPathService::resolvePoolPath($p);
            $pool = preg_match('#^/mnt/user/[^/]+#', $parent) ? $resolver($parent) : null;
            if ($pool === null || $pool === '') {
                return ['status' => 'error', 'message' => 'A folder cannot be made here: ' . $parent
                    . ' is on a /mnt/user share, which can freeze the server under heavy use. Open the pool or disk path instead (for example /mnt/cache/...).'];
            }
            $parent = rtrim($pool, '/');
        }

        $bases = $allowedBases ?? self::PICKER_CREATE_BASES;
        $realParent = ValidationService::validatePath($parent, $bases);
        if ($realParent === false || !is_dir($realParent)) {
            return ['status' => 'error', 'message' => 'A folder can only be made inside /mnt, /home or /root, in a folder that exists.'];
        }
        if (in_array($realParent, self::PICKER_MOUNT_HOLDERS, true) || preg_match('#^/mnt/user0?(/|$)#', $realParent)) {
            return ['status' => 'error', 'message' => 'A folder cannot be made directly in ' . $realParent . '. Open a disk, a pool or a share first.'];
        }
        $fs = $fstype !== null ? (string)$fstype($realParent) : \AICliAgents\Services\StorageTargetService::fstypeAt($realParent);
        if ($fs !== '' && stripos($fs, 'fuse') === 0) {
            return ['status' => 'error', 'message' => 'A folder cannot be made here: ' . $realParent . ' is a FUSE share mount. Open the pool or disk path instead.'];
        }
        if (!is_writable($realParent)) {
            return ['status' => 'error', 'message' => 'You cannot write to ' . $realParent . '.'];
        }
        $destination = $realParent . '/' . $rawName;
        if (file_exists($destination) || is_link($destination)) {
            return ['status' => 'error', 'message' => 'A file or folder named "' . $rawName . '" already exists here.'];
        }
        error_clear_last();
        if (!@mkdir($destination, 0777, false)) {
            $phpError = error_get_last();
            aicli_log("Folder browser: could not create $destination" . ($phpError !== null ? ': ' . $phpError['message'] : ''), AICLI_LOG_ERROR, 'UtilityHandler');
            return ['status' => 'error', 'message' => 'The folder could not be made. Check the permissions of ' . $realParent . '.'];
        }
        $created = realpath($destination);
        if ($created === false || dirname($created) !== $realParent) {
            aicli_log("Folder browser: created $destination but could not verify it", AICLI_LOG_ERROR, 'UtilityHandler');
            return ['status' => 'error', 'message' => 'The folder was made but could not be checked safely.'];
        }
        aicli_log("Folder browser: folder created: $created", AICLI_LOG_INFO, 'UtilityHandler');
        $logical = ($logicalParent !== $parent) ? $logicalParent . '/' . $rawName : $created;
        return ['status' => 'ok', 'path' => $logical, 'resolved' => $created];
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
        $rawPath = \AICliAgents\Services\UtilityService::expandAgentHome($rawPath);
        $resolved = ValidationService::validateReadPath($rawPath);
        if ($resolved === false) {
            // A REFUSED location is not a missing file, and the caller must be able to
            // say so. Both used to answer exists:false, so the UI reported "File not
            // found: /tmp/x.md" about a file plainly sitting there — sending the
            // operator to hunt for a typo instead of telling them the location is
            // outside the allowlist. The allowlist itself does not move; only the
            // wording does. Spec: docs/specs/TERMINAL_URL_LINKS.md
            return [
                'status' => 'ok',
                'exists' => false,
                'isFile' => false,
                'path'   => '',
                'reason' => 'outside_allowed_bases',
            ];
        }
        $exists = file_exists($resolved);
        return [
            'status' => 'ok',
            'exists' => $exists,
            'isFile' => $exists && is_file($resolved),
            'path'   => $exists ? $resolved : '',
            'reason' => $exists ? '' : 'not_found',
            'readOnly' => $exists && ValidationService::isReadOnlyPath($resolved),
        ];
    }

    /**
     * D-405: Save a file from base64-encoded POST data (avoids multipart which hangs on Unraid nginx).
     *
     * docs/specs/FILE_VIEWER_SECRET_DROP.md R3: a save is treated as a SECRET
     * write when the caller sets $secretFlag (the "New secret file" client
     * flow) OR the resolved target directory is itself a secrets path
     * (SecretPaths::isSecretDir) — the second check is defense in depth, so
     * overwriting an existing secret file through the normal editor Save
     * button is covered even without the flag. For a secret write: the
     * directory is created 0700 (never 0777), the "make writable" fallback
     * never loosens it to 0777, and the file is chmod'd 0600 immediately
     * after the atomic write lands.
     *
     * Public + explicit-args so it has the same test seam as
     * createDirectory() above; production callers still just pass the
     * $_POST values (see the `save_file` case in handle()).
     */
    public static function saveFile($rawPath, $rawFilename, $b64data, bool $secretFlag = false) {
        aicli_log("[Upload/SaveFile] Received: '$rawFilename' to '$rawPath' (" . strlen((string)$b64data) . " b64 chars)", AICLI_LOG_DEBUG, "UtilityHandler");

        $rawPath = \AICliAgents\Services\UtilityService::expandAgentHome($rawPath);
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

        $isSecret = $secretFlag || \AICliAgents\Services\SecretPaths::isSecretDir($targetPath);

        if (!is_dir($targetPath)) @mkdir($targetPath, $isSecret ? 0700 : 0777, true);
        // Ensure writable — user share dirs created by root (mode 755) may be
        // unwritable for the nobody:users PHP process. chmod is a best-effort
        // attempt; if the caller is nobody and doesn't own the dir it silently
        // no-ops, but for world-writable shares it works. NEVER for a secrets
        // directory: R3 forbids loosening it to 0777 even when it's not
        // writable by this process — the save simply fails instead.
        if (!$isSecret && !is_writable($targetPath)) @chmod($targetPath, 0777);

        // $targetPath is validated by ValidationService::validatePath (whitelisted
        // bases, rejects ../ and prefix-impersonation). $filename is sanitised by
        // ValidationService::sanitizeFilename. Destination cannot escape the
        // allowlisted bases.
        $dest = rtrim($targetPath, '/') . '/' . $filename;
        error_clear_last();
        // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection
        $bytes = @file_put_contents($dest, $data);
        if ($bytes !== false) {
            if ($isSecret) @chmod($dest, 0600);
            aicli_log("[Upload/SaveFile] Complete: $filename ($bytes bytes) saved to $targetPath", AICLI_LOG_INFO, "UtilityHandler");
            return ['status' => 'ok', 'filename' => $filename, 'bytes' => $bytes];
        }
        $phpErr = error_get_last();
        $errDetail = $phpErr ? $phpErr['message'] : 'unknown error';
        aicli_log("[Upload/SaveFile] FAILED: Could not write to $dest — $errDetail", AICLI_LOG_ERROR, "UtilityHandler");
        return ['status' => 'error', 'message' => 'Failed to write file to ' . $dest . ' (' . $errDetail . ')'];
    }

    /**
     * docs/specs/WORKSPACE_UPLOAD_MULTI_CHUNKED.md R3 — the client asks this
     * once per overlay open to size its chunks. `chunk_bytes` is the biggest
     * RAW (pre-base64) chunk that still fits the server's `post_max_size`
     * after base64 inflation (base64 grows data by 4/3) and a 256 KiB margin
     * for the other POST fields, rounded down to a 64 KiB multiple so chunk
     * boundaries stay tidy. `max_file_bytes` is the `upload_max_bytes`
     * plugin setting (0 = no cap).
     *
     * $postMaxSizeIni is a test seam (an ini_get('post_max_size')-shaped
     * string, e.g. '8M') — production callers omit it and the real php.ini
     * value is read. $maxFileBytesOverride is the same kind of seam for the
     * `upload_max_bytes` setting.
     */
    public static function getUploadLimits(?string $postMaxSizeIni = null, ?int $maxFileBytesOverride = null): array {
        $ini = $postMaxSizeIni ?? (string) ini_get('post_max_size');
        $postMaxBytes = self::parseIniBytes($ini);
        if ($postMaxBytes <= 0) {
            // '0' (or unparseable) means PHP places no limit — fall back to a
            // sane chunk size instead of advertising an unbounded/zero chunk.
            $postMaxBytes = 8 * 1024 * 1024;
        }

        $overheadBytes = 262144; // 256 KiB for the other POST fields + multipart-ish overhead
        $rawAvailable = $postMaxBytes - $overheadBytes;
        $chunkBytes = $rawAvailable > 0 ? (int) floor($rawAvailable * 3 / 4) : 65536;
        $chunkBytes = intdiv($chunkBytes, 65536) * 65536;
        if ($chunkBytes < 65536) $chunkBytes = 65536; // never advertise a useless chunk size

        if ($maxFileBytesOverride !== null) {
            $maxFileBytes = max(0, $maxFileBytesOverride);
        } else {
            $config = \AICliAgents\Services\ConfigService::getConfig();
            $raw = $config['upload_max_bytes'] ?? 536870912;
            $maxFileBytes = (is_numeric($raw) && (int)$raw >= 0) ? (int)$raw : 536870912;
        }

        return [
            'status'         => 'ok',
            'post_max_bytes' => $postMaxBytes,
            'chunk_bytes'    => $chunkBytes,
            'max_file_bytes' => $maxFileBytes,
        ];
    }

    /** Parses a php.ini size string ('8M', '512K', '1G', or a plain number) into bytes. */
    private static function parseIniBytes(string $val): int {
        $val = trim($val);
        if ($val === '') return 0;
        $suffix = strtolower(substr($val, -1));
        $num = (float) $val;
        switch ($suffix) {
            case 'g': return (int) ($num * 1024 * 1024 * 1024);
            case 'm': return (int) ($num * 1024 * 1024);
            case 'k': return (int) ($num * 1024);
            default:  return (int) $num;
        }
    }

    /**
     * docs/specs/WORKSPACE_UPLOAD_MULTI_CHUNKED.md R4 — appends one base64
     * chunk to `<dest>.part-<uploadId>`, verifying `chunkIndex` is the next
     * one expected (an `.idx` sidecar holds the last chunk index actually
     * written — never trust the client's own count). Index 0 always resets:
     * it truncates any previous part for this uploadId and sweeps parts
     * older than 1 hour out of the target directory (a client that never
     * came back after chunk 0 must not leak a part file forever) — this
     * script-level sweep is now backed by a supervisor-tick one across every
     * workspace path (activity-sweep.php, REVIEW_2026-09-13_EVENTS_AND_SECURITY.md
     * S6), for a folder that never receives another upload at all. Under a
     * secrets directory the growing part is `chmod 0600` on every chunk, not
     * only at finalize (S9). Each chunk is also refused, and the part
     * removed, once it would grow past the configured `upload_max_bytes`
     * (S6). On the last chunk the part is renamed onto `<dest>` — the same
     * secrets 0600 rule as saveFile() applies. Any failure (bad uploadId
     * shape, chunk out of order, base64 that won't decode, a write that
     * fails, the size cap) removes the in-progress part so a retry starts
     * clean.
     *
     * Public + explicit-args for the same test seam as saveFile() above.
     */
    public static function saveFileChunk($rawPath, $rawFilename, $b64chunk, string $uploadId, int $chunkIndex, int $totalChunks, bool $secretFlag = false): array {
        $logCtx = "UtilityHandler";

        if (!preg_match('/^[0-9a-f]{16}$/i', $uploadId)) {
            aicli_log("[Upload/Chunk] REJECTED: malformed uploadId", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Invalid upload id'];
        }
        if ($totalChunks < 1 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            aicli_log("[Upload/Chunk] REJECTED: chunkIndex $chunkIndex out of range for totalChunks $totalChunks (uploadId $uploadId)", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Invalid chunk index'];
        }

        $rawPath = \AICliAgents\Services\UtilityService::expandAgentHome($rawPath);
        $targetPath = ValidationService::validatePath($rawPath);
        $filename = ValidationService::sanitizeFilename($rawFilename);

        if ($targetPath === false) {
            aicli_log("[Upload/Chunk] REJECTED: Path validation failed for '$rawPath'", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Path validation failed: ' . $rawPath];
        }
        if (empty($filename)) {
            aicli_log("[Upload/Chunk] REJECTED: Empty filename after sanitization", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }
        if (empty($b64chunk)) {
            aicli_log("[Upload/Chunk] REJECTED: No chunk data received", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'No chunk data received'];
        }
        if (!\AICliAgents\Services\StorageMountService::isBackingMountAvailable($targetPath)) {
            return ['status' => 'error', 'message' => 'Target storage is not mounted'];
        }

        $isSecret = $secretFlag || \AICliAgents\Services\SecretPaths::isSecretDir($targetPath);
        if (!is_dir($targetPath)) @mkdir($targetPath, $isSecret ? 0700 : 0777, true);
        if (!$isSecret && !is_writable($targetPath)) @chmod($targetPath, 0777);

        $dest = rtrim($targetPath, '/') . '/' . $filename;
        $part = $dest . '.part-' . $uploadId;
        $idxFile = $part . '.idx';

        if ($chunkIndex === 0) {
            self::sweepStaleUploadParts($targetPath);
            @unlink($part);
            @unlink($idxFile);
            aicli_log("[Upload/Chunk] Start: uploadId=$uploadId '$filename' totalChunks=$totalChunks target='$targetPath'", AICLI_LOG_INFO, $logCtx);
        } else {
            $lastWritten = self::readPartIndex($idxFile);
            if ($lastWritten === null || $chunkIndex !== $lastWritten + 1) {
                @unlink($part);
                @unlink($idxFile);
                self::forgetUploadPart($uploadId);
                aicli_log("[Upload/Chunk] REJECTED: chunk $chunkIndex out of order for uploadId $uploadId (expected " . ($lastWritten === null ? 'chunk 0 first' : $lastWritten + 1) . ")", AICLI_LOG_ERROR, $logCtx);
                return ['status' => 'error', 'message' => 'Chunk received out of order. Restart the upload.'];
            }
        }

        $data = base64_decode($b64chunk, true);
        if ($data === false) {
            @unlink($part);
            @unlink($idxFile);
            self::forgetUploadPart($uploadId);
            aicli_log("[Upload/Chunk] REJECTED: base64_decode failed for chunk $chunkIndex (uploadId $uploadId)", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Invalid base64 data in chunk'];
        }

        $mode = ($chunkIndex === 0) ? 'wb' : 'ab';
        error_clear_last();
        $fp = @fopen($part, $mode);
        if ($fp === false) {
            $phpErr = error_get_last();
            aicli_log("[Upload/Chunk] FAILED: could not open part file $part (" . ($phpErr['message'] ?? 'unknown') . ")", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Failed to open the upload for writing'];
        }
        $written = fwrite($fp, $data);
        fclose($fp);
        if ($chunkIndex === 0) self::registerUploadPart($uploadId, $part);
        if ($written === false) {
            @unlink($part);
            @unlink($idxFile);
            self::forgetUploadPart($uploadId);
            aicli_log("[Upload/Chunk] FAILED: write failed for chunk $chunkIndex to $part", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Failed to write chunk to disk'];
        }
        // S9 (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S9): a part file under a
        // secrets dir stays owner-only while it grows, not just once finalized —
        // the same $isSecret detection saveFile() and the finalize step below use.
        if ($isSecret) @chmod($part, 0600);

        // S6: refuse this chunk once the growing part would exceed the
        // configured upload_max_bytes cap (0 = no cap), and remove the part so
        // a retry starts clean instead of resuming an already-oversized file.
        $maxFileBytes = self::getUploadLimits()['max_file_bytes'];
        if ($maxFileBytes > 0) {
            $partSize = @filesize($part);
            if ($partSize !== false && $partSize > $maxFileBytes) {
                @unlink($part);
                @unlink($idxFile);
                self::forgetUploadPart($uploadId);
                aicli_log("[Upload/Chunk] REJECTED: part exceeded the upload_max_bytes limit ($maxFileBytes bytes) for uploadId $uploadId", AICLI_LOG_ERROR, $logCtx);
                return ['status' => 'error', 'message' => 'This upload is larger than the configured limit.'];
            }
        }

        @file_put_contents($idxFile, (string) $chunkIndex);
        aicli_log("[Upload/Chunk] Chunk $chunkIndex/$totalChunks written: $written bytes to $part", AICLI_LOG_DEBUG, $logCtx);

        if ($chunkIndex + 1 < $totalChunks) {
            return ['status' => 'ok', 'filename' => $filename, 'complete' => false];
        }

        // Last chunk: finalize by renaming the part onto the real destination.
        if (!@rename($part, $dest)) {
            aicli_log("[Upload/Chunk] FAILED: could not rename $part to $dest", AICLI_LOG_ERROR, $logCtx);
            return ['status' => 'error', 'message' => 'Failed to finalize the upload'];
        }
        @unlink($idxFile);
        self::forgetUploadPart($uploadId);
        if ($isSecret) @chmod($dest, 0600);
        $finalSize = filesize($dest);
        aicli_log("[Upload/Chunk] Complete: $filename ($finalSize bytes, $totalChunks chunks) saved to $targetPath", AICLI_LOG_INFO, $logCtx);
        return ['status' => 'ok', 'filename' => $filename, 'bytes' => $finalSize, 'complete' => true];
    }

    /**
     * Part-file index (REVIEW_2026-09-13 S6, as built after the gate found the
     * first version walking every workspace tree on the supervisor tick — a
     * FUSE hazard on /mnt/user and a stall of the supervisor queue). A chunked
     * upload registers its part file here (tmpfs, one small file per
     * uploadId) and forgets it when the part is finalized or removed. The
     * supervisor sweep reads ONLY this index; it never walks a directory.
     * Test seam: $uploadPartsIndexDir.
     */
    public static ?string $uploadPartsIndexDir = null;

    private static function uploadPartsIndexDir(): string {
        return self::$uploadPartsIndexDir ?? '/tmp/unraid-aicliagents/upload-parts';
    }

    private static function registerUploadPart(string $uploadId, string $part): void {
        if (!preg_match('/^[0-9a-f]{16}$/', $uploadId)) return;
        $dir = self::uploadPartsIndexDir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents("$dir/$uploadId", $part);
    }

    private static function forgetUploadPart(string $uploadId): void {
        if (!preg_match('/^[0-9a-f]{16}$/', $uploadId)) return;
        @unlink(self::uploadPartsIndexDir() . "/$uploadId");
    }

    /**
     * Removes every registered part file (and its .idx sidecar) whose index
     * entry is older than $ttlSeconds, plus the entry itself. Returns the
     * number of part files removed. Reads only the index directory.
     */
    public static function sweepStaleUploadPartsFromIndex(int $ttlSeconds = 3600, ?int $now = null): int {
        $dir = self::uploadPartsIndexDir();
        if (!is_dir($dir)) return 0;
        $now = $now ?? time();
        $removed = 0;
        foreach ((scandir($dir) ?: []) as $entry) {
            if (!preg_match('/^[0-9a-f]{16}$/', $entry)) continue;
            $marker = "$dir/$entry";
            $mtime = @filemtime($marker);
            if ($mtime === false || ($now - $mtime) < $ttlSeconds) continue;
            $part = trim((string)@file_get_contents($marker));
            // The recorded path must be this upload's own part file, never anything else.
            if ($part !== '' && substr($part, -22) === '.part-' . $entry && is_file($part)) {
                if (@unlink($part)) $removed++;
                @unlink($part . '.idx');
            }
            @unlink($marker);
        }
        return $removed;
    }

    /** Reads the last-written chunk index from an `.idx` sidecar, or null if absent/unreadable. */
    private static function readPartIndex(string $idxFile): ?int {
        if (!is_file($idxFile)) return null;
        $raw = @file_get_contents($idxFile);
        if ($raw === false || $raw === '' || !ctype_digit(trim($raw))) return null;
        return (int) trim($raw);
    }

    /**
     * Removes `.part-*` files (and their `.idx` sidecars) older than 1 hour
     * from $dir. Runs on every chunk-0 request so an upload nobody ever
     * finished (browser closed, network dropped before the last chunk)
     * cannot accumulate forever. Errors are best-effort — a sweep that can't
     * read the directory must never block the chunk it was called for.
     */
    private static function sweepStaleUploadParts(string $dir): void {
        $entries = @scandir($dir);
        if ($entries === false) return;
        $cutoff = time() - 3600;
        foreach ($entries as $entry) {
            if (strpos($entry, '.part-') === false) continue;
            $path = rtrim($dir, '/') . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
                aicli_log("[Upload/Chunk] Swept stale part: $path", AICLI_LOG_DEBUG, "UtilityHandler");
            }
        }
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
