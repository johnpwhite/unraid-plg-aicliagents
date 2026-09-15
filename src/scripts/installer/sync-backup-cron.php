<?php
/**
 * HOME_BACKUP.md R1/R12: (re)install the plugin-owned home-backup cron from
 * the saved config, at install/upgrade time — the same "finalize re-derives
 * the cron from cfg" step finalize.sh already does inline for the health
 * check (grep health_check_schedule) and the agent-check (grep
 * version_check_schedule). Kept as a script FILE, not an inline `php -r`, for
 * the same reason format-migrate.php is: a namespaced facade call mangled by
 * bash backslash handling is the publish anti-pattern this avoids.
 *
 * No arguments: BackupCronService::sync() reads the config itself.
 */

require_once '/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php';

use AICliAgents\Services\BackupCronService;

BackupCronService::sync();
