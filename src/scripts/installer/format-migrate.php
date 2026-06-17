<?php
/**
 * Plugin version-bump format-migration trigger (Follow-on #1).
 *
 * Invoked by finalize.sh AFTER the legacy migrate/cleanup/resurrection helpers and
 * BEFORE the new version is saved, so it can read the OLD version from config.
 * Runs FileStorage::migrateFormat ONCE per genuine version bump (version-gated)
 * under the crash-safe marker — the SINGLE explicit "version bumped → format
 * migration point" so a normal boot never has to discover a format drift and halt.
 *
 * Kept as a script FILE (not an inline `php -r`) so the namespaced facade calls
 * are not mangled by bash backslash handling — see the publish anti-pattern check.
 *
 * argv[1] = new plugin version (the .plg $VERSION).
 */

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';
require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/services/FileStorage.php';

use AICliAgents\Services\FileStorage;

$newVer = $argv[1] ?? '';
$cfg = function_exists('getAICliConfig') ? getAICliConfig() : [];
$oldVer = isset($cfg['version']) ? (string) $cfg['version'] : '';

if (FileStorage::formatMigrationNeeded($oldVer, $newVer)) {
    FileStorage::migrateFormat($oldVer, $newVer);
}
