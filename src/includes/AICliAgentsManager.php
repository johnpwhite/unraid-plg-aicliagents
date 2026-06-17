<?php
/**
 * <module_context>
 *     <name>AICliAgentsManager</name>
 *     <description>Facade entry point delegating to atomic services.</description>
 *     <dependencies>LogService, ConfigService, InitService, PermissionService, AgentRegistry, ProcessManager, StorageMountService, StorageMetricsService, StorageMigrationService</dependencies>
 *     <constraints>Acts as backwards-compatible procedural bridge.</constraints>
 * </module_context>
 */

// Bug #1043: route every tmux command this plugin shells out to (capture-pane,
// send-keys, kill-session, ...) at a plugin-private socket directory instead of
// the shared /tmp/tmux-<uid>, which other processes can leave with permissions
// tmux rejects. Child processes the plugin spawns inherit this env var.
putenv('TMUX_TMPDIR=/tmp/unraid-aicliagents/tmux');

// 1. Include Atomic Services
require_once __DIR__ . '/services/LogService.php';
require_once __DIR__ . '/services/AtomicWriteService.php';
require_once __DIR__ . '/services/ConfigService.php';
require_once __DIR__ . '/services/InitService.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/AgentRegistry.php';
require_once __DIR__ . '/services/ProcessManager.php';
require_once __DIR__ . '/services/StoragePathResolver.php';
require_once __DIR__ . '/services/LifecycleLogService.php';
require_once __DIR__ . '/services/LayerManifestService.php';
require_once __DIR__ . '/services/BootIntegrityService.php';
require_once __DIR__ . '/services/HaltService.php';
require_once __DIR__ . '/services/SupervisorService.php';
require_once __DIR__ . '/services/StorageMountService.php';
require_once __DIR__ . '/services/StorageMetricsService.php';
require_once __DIR__ . '/services/FileStorage.php';   // Epic #1310 — the storage facade (now consumed)
require_once __DIR__ . '/services/StorageMigrationService.php';
require_once __DIR__ . '/services/StorageTargetService.php';   // S-11 #1355 — target picker enumeration
require_once __DIR__ . '/services/TaskService.php';
require_once __DIR__ . '/services/InstallerService.php';
require_once __DIR__ . '/services/TerminalService.php';
require_once __DIR__ . '/services/AutoLaunchService.php';
require_once __DIR__ . '/services/UtilityService.php';
require_once __DIR__ . '/services/NchanService.php';
require_once __DIR__ . '/services/ActivityService.php';
require_once __DIR__ . '/services/VersionClassifier.php';
require_once __DIR__ . '/services/VersionCheckService.php';
require_once __DIR__ . '/services/TmuxService.php';
require_once __DIR__ . '/services/ArgsService.php';
require_once __DIR__ . '/services/SecretService.php';
require_once __DIR__ . '/services/EnvService.php';
// R-08 (Feature #1371) — diagnostics bundle + fail-closed redaction
require_once __DIR__ . '/services/RedactionService.php';
require_once __DIR__ . '/services/DiagnosticsService.php';
// R-09/R-14 (Feature #1372) — proactive health checks + status chip + cron notify
require_once __DIR__ . '/services/HealthService.php';
// Feature #1382 — programmatic state-invariant auditor (live-box twin of the
// tests/lib/state-ledger.sh harness ledger). Depends on HealthService above.
require_once __DIR__ . '/services/StorageStateAuditService.php';
// T-11 (Feature #1360) — workspace export/import bundles
require_once __DIR__ . '/services/WorkspaceBundleService.php';
// Config Hub (OP #1362 / H-01 phase 1 — canonical MCP store + per-vendor projectors)
require_once __DIR__ . '/services/hub/HubStore.php';
require_once __DIR__ . '/services/hub/Transpiler.php';
require_once __DIR__ . '/services/hub/VendorProjector.php';
require_once __DIR__ . '/services/hub/JsonMcpProjector.php';
require_once __DIR__ . '/services/hub/OpencodeStyleMcpProjector.php'; // mcp-key base (OpenCode/Kilo) — before its subclasses
require_once __DIR__ . '/services/hub/ClaudeProjector.php';
require_once __DIR__ . '/services/hub/GeminiProjector.php';
require_once __DIR__ . '/services/hub/QwenProjector.php';
require_once __DIR__ . '/services/hub/OpencodeProjector.php';
require_once __DIR__ . '/services/hub/KilocodeProjector.php';
require_once __DIR__ . '/services/hub/AntigravityProjector.php'; // extends GeminiProjector — after it
require_once __DIR__ . '/services/hub/FactoryProjector.php';
require_once __DIR__ . '/services/hub/NanocoderProjector.php';
require_once __DIR__ . '/services/hub/CopilotProjector.php';
require_once __DIR__ . '/services/hub/CodexProjector.php';
require_once __DIR__ . '/services/hub/GooseProjector.php';
// Config Hub phase 2 (OP #1363 / H-02 — instruction-file projection)
require_once __DIR__ . '/services/hub/InstructionProjector.php';
require_once __DIR__ . '/services/hub/KiloInstructionProjector.php'; // extends InstructionProjector
// Config Hub phase 3 (OP #1364 / H-03 — skills/commands mirrored-tree projection)
require_once __DIR__ . '/services/hub/TreeProjector.php';
require_once __DIR__ . '/services/hub/GeminiCommandsProjector.php'; // extends TreeProjector
require_once __DIR__ . '/services/hub/HubProjector.php';
// Config Hub git layer (OP #1365 / H-04 — git-backed home config, opt-in)
require_once __DIR__ . '/services/hub/GitHomeService.php';
require_once __DIR__ . '/services/sources/AgentSource.php';
require_once __DIR__ . '/services/sources/NpmSource.php';
require_once __DIR__ . '/services/sources/UrlValidator.php';
require_once __DIR__ . '/services/sources/GithubReleaseSource.php';
require_once __DIR__ . '/services/sources/CurlInstallSource.php';
require_once __DIR__ . '/services/sources/TarballSource.php';
require_once __DIR__ . '/services/sources/SourceResolver.php';

use AICliAgents\Services\LogService;
use AICliAgents\Services\ConfigService;
use AICliAgents\Services\InitService;
use AICliAgents\Services\PermissionService;
use AICliAgents\Services\AgentRegistry;
use AICliAgents\Services\ProcessManager;
use AICliAgents\Services\StorageMountService;
use AICliAgents\Services\StorageMetricsService;
use AICliAgents\Services\FileStorage;
use AICliAgents\Services\StorageMigrationService;
use AICliAgents\Services\InstallerService;
use AICliAgents\Services\TerminalService;
use AICliAgents\Services\UtilityService;
use AICliAgents\Services\NchanService;

// Force system/user timezone if available, otherwise fallback to UTC
if (file_exists('/var/local/emhttp/var.ini')) {
    $var = @parse_ini_file('/var/local/emhttp/var.ini');
    if (!empty($var['timeZone'])) {
        @date_default_timezone_set($var['timeZone']);
    } else {
        date_default_timezone_set('UTC');
    }
} else {
    date_default_timezone_set('UTC');
}

// Global Constants for Logging
define('AICLI_LOG_ERROR', LogService::LOG_ERROR);
define('AICLI_LOG_WARN',  LogService::LOG_WARN);
define('AICLI_LOG_INFO',  LogService::LOG_INFO);
define('AICLI_LOG_DEBUG', LogService::LOG_DEBUG);

/**
 * Wrapper for the LogService.
 */
function aicli_log($message, $level = AICLI_LOG_INFO, $context = "AICliAgents") {
    LogService::log($message, $level, $context);
}

/**
 * Wrapper for timestamp formatting.
 */
function aicli_get_formatted_timestamp($includeDate = true) {
    return LogService::getFormattedTimestamp($includeDate);
}

/**
 * Wrapper for the ConfigService.
 */
function getAICliConfig() {
    return ConfigService::getConfig();
}

/**
 * Wrapper for saving plugin configuration.
 */
function saveAICliConfig($newConfig, $notify = true) {
    return ConfigService::saveConfig($newConfig, $notify);
}

/**
 * Wrapper for ensuring Nginx configuration.
 */
function ensureNginxConfig() {
    ConfigService::ensureNginxConfig();
}

/**
 * Wrapper for one-time initialization logic.
 */
function aicli_init_plugin() {
    InitService::initPlugin();
}

/**
 * Wrapper for ensuring the plugin is initialized for the request.
 */
function aicli_ensure_init($skipMount = false) {
    InitService::ensureInit($skipMount);
}

/**
 * Wrapper for initializing a user's working directory.
 */
function aicli_init_working_dir($user, $force = false) {
    return \AICliAgents\Services\TaskService::persistHome($user, $force);
}

/**
 * Wrapper for home directory persistence (blocking — waits for bake).
 */
function aicli_persist_home($user, $force = false) {
    return \AICliAgents\Services\TaskService::persistHome($user, $force);
}

/**
 * Non-blocking home persist: attempts bake but skips if lock is held.
 * Data is safe in ZRAM overlay; the periodic daemon or next idle persist catches it.
 */
function aicli_persist_home_nonblocking($user) {
    return \AICliAgents\Services\TaskService::persistHomeNonBlocking($user);
}

/**
 * Wrapper for boot-time state restoration.
 */
function aicli_boot_resurrection() {
    InitService::bootResurrection();
}

/**
 * Helper to check if a command exists in the system path. Walks the PATH
 * directly instead of shelling out to `command -v` — the prior wrapper
 * called UtilityService::commandExists which was never implemented.
 */
if (!function_exists('command_exists')) {
    function command_exists($cmd) {
        $name = (string)$cmd;
        if ($name === '' || preg_match('#[/\\\\]#', $name)) return false;
        $paths = explode(PATH_SEPARATOR, (string)getenv('PATH'));
        foreach ($paths as $dir) {
            $full = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($full) && is_executable($full)) return true;
        }
        return false;
    }
}

/**
 * Wrapper for ensuring agent storage is mounted.
 */
function aicli_ensure_agent_mounted($agentId) {
    return FileStorage::ensureReady("agent/$agentId")->ok;   // Epic #1310: facade intent
}

/**
 * Wrapper for getting storage status.
 */
function aicli_get_storage_status() {
    return StorageMetricsService::getStatus();
}

/**
 * Wrapper for unlocking agent storage (RW).
 * Note: SquashFS is RO, but OverlayFS is RW via ZRAM.
 */
function aicli_agent_storage_unlock() {
    return true; 
}

/**
 * Wrapper for locking agent storage (RO).
 */
function aicli_agent_storage_lock() {
    return true;
}

/**
 * Wrapper for expanding agent or home storage.
 */
function aicli_expand_storage($type, $inc) {
    return StorageMigrationService::expandStorage($inc);
}

/**
 * Wrapper for shrinking agent or home storage.
 */
function aicli_shrink_storage($type, $dec) {
    if ($type === 'home' || strpos($type, 'home_') === 0) {
        $user = strpos($type, 'home_') === 0 ? substr($type, 5) : getAICliConfig()['user'];
        return StorageMigrationService::shrinkHomeStorage($user);
    }
    return StorageMigrationService::shrinkStorage();
}

/**
 * Wrapper for repairing agent storage.
 */
function aicli_repair_agent_storage($id = 'default') {
    return FileStorage::ensureReady("agent/$id")->ok;   // Epic #1310: facade intent
}

/**
 * Wrapper for the nuclear storage rebuild option.
 */
function aicli_nuclear_rebuild_agent_storage($id = 'default') {
    return StorageMigrationService::nuclearRebuild('agent', $id);
}

/**
 * Wrapper for migrating persistence storage.
 */
function aicli_migrate_persistence($path) {
    return StorageMigrationService::migratePersistence($path);
}

/**
 * Wrapper for migrating agent storage path.
 */
function aicli_migrate_agent_storage($path) {
    return StorageMigrationService::migrateAgentStorage($path);
}

/**
 * Wrapper for legacy home path migration. The real service never had a
 * migrateHomePath() method — the available migration entry points are
 * migratePersistence() (user-home data) and migrateAgentStorage() (agent
 * binaries). Route through migratePersistence with the current home dir as
 * the target so existing callers (finalize.sh post-install PHP block) don't
 * fatal. Returns a bool for caller inspection.
 */
function aicli_migrate_home_path() {
    $cfg = getAICliConfig();
    $homePath = $cfg['home_storage_path'] ?? ($cfg['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents');
    return StorageMigrationService::migratePersistence($homePath);
}

/**
 * Wrapper for repairing a specific user's home storage.
 */
function aicli_repair_home_storage($user) {
    return StorageMountService::repairHomeStorage($user);
}

/**
 * Wrapper for checking session status.
 */
function isAICliRunning($id = 'default') {
    return ProcessManager::isRunning($id);
}

/**
 * Wrapper for stopping a terminal session.
 */
function stopAICliTerminal($id = 'default', $killTmux = false) {
    return ProcessManager::stopTerminal($id, $killTmux);
}

/**
 * Wrapper for global AI session eviction (Array Stop Interceptor).
 */
function aicli_evict_all() {
    return ProcessManager::evictAll();
}

/**
 * Wrapper for targeted AI session eviction.
 */
function aicli_evict_targeted($ids) {
    return ProcessManager::evictTargeted($ids);
}

/**
 * Wrapper for getting the agent registry.
 */
function getAICliAgentsRegistry() {
    return AgentRegistry::getRegistry();
}

/**
 * Wrapper for checking agent updates.
 */
function checkAgentUpdates() {
    return AgentRegistry::checkUpdates();
}

/**
 * Wrapper for version persistence.
 */
function getAICliVersions() {
    return AgentRegistry::getVersions();
}

/**
 * Wrapper for saving agent version.
 */
function saveAICliVersion($agentId, $version) {
    return AgentRegistry::saveVersion($agentId, $version);
}

/**
 * Wrapper for starting a terminal session.
 */
function startAICliTerminal($id = 'default', $path = null, $chatId = null, $agentId = 'gemini-cli') {
    return TerminalService::startTerminal($id, $path, $chatId, $agentId);
}

/**
 * Wrapper for finding a chat session.
 */
function findAICliChatSession($path, $id = null, $agentId = 'gemini-cli') {
    // TerminalService::findSession only accepts (path, agentId). The $id
    // parameter was historically passed but ignored — keep the wrapper
    // signature for backward compat while dropping the useless third arg.
    return TerminalService::findSession($path, $agentId);
}

/**
 * Wrapper for session garbage collection.
 */
function gcAICliSessions() {
    return TerminalService::gc();
}

/**
 * Wrapper for agent installation.
 */
function installAgent($agentId, $targetVersion = null) {
    return InstallerService::installAgent($agentId, $targetVersion);
}

/**
 * Wrapper for agent uninstallation.
 */
function uninstallAgent($agentId) {
    return InstallerService::uninstallAgent($agentId);
}

/**
 * Wrapper for legacy cleanup.
 */
function aicli_cleanup_legacy() {
    InstallerService::cleanupLegacy();
}

/**
 * Wrapper for menu visibility management.
 */
function updateAICliMenuVisibility($enable) {
    InstallerService::updateMenuVisibility($enable);
}

/**
 * Wrapper for workspace persistence.
 */
function aicli_get_workspaces() {
    return ConfigService::getWorkspaces();
}

/**
 * Wrapper for saving workspace state.
 * Writes workspaces.json to the overlay (fast) and attempts a non-blocking persist.
 * If a bake is already in progress, the data is safe in ZRAM and will be captured next cycle.
 */
function aicli_save_workspaces($data) {
    $res = \AICliAgents\Services\ConfigService::saveWorkspaces($data);

    $config = getAICliConfig();
    $user = $config['user'] ?? 'root';
    if (empty($user)) $user = 'root';

    // Non-blocking persist: try to bake, but don't wait if another bake is running.
    // The file is already written to the ZRAM overlay — it's safe. The bake is just
    // an optimization to flush to Flash sooner. The supervisor's next idle-tick
    // will catch it if we skip here.
    aicli_persist_home_nonblocking($user);

    return $res;
}

/**
 * Wrapper for getting workspace envs.
 */
function getWorkspaceEnvs($path, $agentId) {
    return ConfigService::getWorkspaceEnvs($path, $agentId);
}

/**
 * Wrapper for saving workspace envs.
 */
function saveWorkspaceEnvs($path, $agentId, $envs) {
    $res = \AICliAgents\Services\ConfigService::saveWorkspaceEnvs($path, $agentId, $envs);
    
    // D-350: Immediate Persistence
    $config = getAICliConfig();
    $user = $config['user'] ?? 'root';
    if (empty($user)) $user = 'root';
    
    aicli_log("Workspace ENV changed ($agentId). Triggering immediate home persistence for $user.", AICLI_LOG_INFO, "AICliManager");
    aicli_persist_home($user, true);
    
    return $res;
}

/**
 * Wrapper for fixing workspace permissions.
 */
function aicli_fix_workspace_permissions($path, $agentId) {
    return PermissionService::fixWorkspacePermissions($path, $agentId);
}

/**
 * Wrapper for enforcing plugin permissions.
 */
function aicli_enforce_permissions() {
    return PermissionService::enforcePluginPermissions();
}

/**
 * Utility Wrapper: Background Execution.
 */
function aicli_exec_bg($cmd) {
    return UtilityService::execBg($cmd);
}

/**
 * Utility Wrapper: GUI Notification.
 */
function aicli_notify($message, $subject = "AICliAgents") {
    return UtilityService::notify($message, $subject);
}

/**
 * Utility Wrapper: User Retrieval.
 */
function getUnraidUsers() {
    return UtilityService::getUnraidUsers();
}

/**
 * Utility Wrapper: User Creation.
 */
function createUnraidUser($username, $password, $description = "") {
    return UtilityService::createUser($username, $password, $description);
}

/**
 * Utility Wrapper: File Tailing.
 */
function aicli_tail($file, $lines = 100) {
    return UtilityService::tail($file, $lines);
}

/**
 * Utility Wrapper: Working Directory Retrieval.
 */
function aicli_get_work_dir($user) {
    return UtilityService::getWorkDir($user);
}

/**
 * Updates the installation status file for frontend polling.
 */
function setInstallStatus($message, $progress, $agentId = '', $reason = '') {
    return UtilityService::setInstallStatus($message, $progress, $agentId, $reason);
}

// 3. Global Path Helpers (Legacy Support)
function getAICliSock($id = 'default') { return UtilityService::getSockPath($id); }
function getAICliPidFile($id = 'default') { return UtilityService::getPidPath($id); }
function getAICliChatIdFile($id = 'default') { return UtilityService::getChatIdPath($id); }
function getAICliAgentIdFile($id = 'default') { return UtilityService::getAgentIdPath($id); }
function getAICliWorkingDirFile($id = 'default') { return UtilityService::getWorkDirFilePath($id); }

// Final facade initialization: Load Config on include
ConfigService::ensureNginxConfig();
