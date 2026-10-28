<?php
/**
 * <module_context>
 *     <name>NotebookLeanBoot</name>
 *     <description>The small start-up for the Notebook read actions (ValidationService::LEAN_NOTEBOOK_ACTIONS). It loads the Notebook files and the few services they need, and nothing else. The full start-up (AICliAgentsManager.php and every handler) is about 6 MB of PHP; the server has no opcache, so it compiles all of it again on every request. That cost about 180 ms for each Notebook call, also for a call that reads one small file.</description>
 *     <dependencies>SecretPaths, LogService, ConfigService, PerfTrace, NchanService, NotebookHandler (and what they load)</dependencies>
 *     <constraints>Never loads AICliAgentsManager.php. It defines the same log constants and the same two helper functions as the Manager, each guarded, so the Manager can load later in the same request without a "cannot redeclare" fatal. If some code needs a class this file did not load, the fallback loader at the end loads the full start-up and the call goes on (slower, still correct). Spec: docs/specs/NOTEBOOK_LEAN_BOOT.md (Forgejo #422).</constraints>
 * </module_context>
 */

defined('AICLI_GEN_SRC') || define('AICLI_GEN_SRC', dirname(__DIR__));

require_once __DIR__ . '/services/SecretPaths.php';
require_once __DIR__ . '/services/LogService.php';
require_once __DIR__ . '/services/ConfigService.php';
require_once __DIR__ . '/services/PerfTrace.php';
require_once __DIR__ . '/services/NchanService.php';
require_once __DIR__ . '/handlers/NotebookHandler.php';

// Same time zone rule as the Manager.
if (file_exists('/var/local/emhttp/var.ini')) {
    $aicliLeanVar = @parse_ini_file('/var/local/emhttp/var.ini');
    if (!empty($aicliLeanVar['timeZone'])) {
        @date_default_timezone_set($aicliLeanVar['timeZone']);
    } else {
        date_default_timezone_set('UTC');
    }
    unset($aicliLeanVar);
} else {
    date_default_timezone_set('UTC');
}

defined('AICLI_LOG_ERROR') || define('AICLI_LOG_ERROR', \AICliAgents\Services\LogService::LOG_ERROR);
defined('AICLI_LOG_WARN')  || define('AICLI_LOG_WARN',  \AICliAgents\Services\LogService::LOG_WARN);
defined('AICLI_LOG_INFO')  || define('AICLI_LOG_INFO',  \AICliAgents\Services\LogService::LOG_INFO);
defined('AICLI_LOG_DEBUG') || define('AICLI_LOG_DEBUG', \AICliAgents\Services\LogService::LOG_DEBUG);

if (!function_exists('aicli_log')) {
    /** Wrapper for the LogService (the Manager defines the same one). */
    function aicli_log($message, $level = AICLI_LOG_INFO, $context = "AICliAgents") {
        \AICliAgents\Services\LogService::log($message, $level, $context);
    }
}
if (!function_exists('getAICliConfig')) {
    /** Wrapper for the ConfigService (the Manager defines the same one). */
    function getAICliConfig() {
        return \AICliAgents\Services\ConfigService::getConfig();
    }
}

// Safety net: a class of the plugin that this start-up did not load. Load the full start-up once and go on.
spl_autoload_register(static function (string $class): void {
    static $done = false;
    if ($done || strncmp($class, 'AICliAgents\\', 12) !== 0) return;
    $done = true;
    require_once AICLI_GEN_SRC . '/includes/AICliAgentsManager.php';
});
