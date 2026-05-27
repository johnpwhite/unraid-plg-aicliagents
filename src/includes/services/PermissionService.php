<?php
/**
 * <module_context>
 *     <name>PermissionService</name>
 *     <description>Permission and security management for AICliAgents.</description>
 *     <dependencies>LogService</dependencies>
 *     <constraints>Under 100 lines. Focuses on RAM/Flash security and workspace alignment.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class PermissionService {
    /**
     * Fixes permissions for a specific workspace path to ensure SMB and agent compatibility.
     * @param string $path The workspace path.
     * @param string $agentId The ID of the agent using the workspace.
     */
    public static function fixWorkspacePermissions($path, $agentId) {
        if (empty($path) || !is_dir($path)) {
            return;
        }

        // D-158: Align external workspace metadata to nobody:users for SMB compatibility
        // This ensures that files created by the agent are accessible via Unraid shares.
        $cmd = "chown -R nobody:users " . escapeshellarg($path) . " > /dev/null 2>&1";
        exec($cmd);
        
        $cmd = "chmod -R 775 " . escapeshellarg($path) . " > /dev/null 2>&1";
        exec($cmd);
        
        LogService::log("Aligned permissions for workspace: $path (Agent: $agentId)", LogService::LOG_DEBUG, "PermissionService");
    }

    /**
     * Enforces security on sensitive plugin assets.
     */
    public static function enforcePluginPermissions() {
        $bootConfig = "/boot/config/plugins/unraid-aicliagents";
        $secretsFile = "$bootConfig/secrets.cfg";

        if (file_exists($secretsFile)) {
            @chmod($secretsFile, 0600);
            LogService::log("Secured Secrets Vault (0600).", LogService::LOG_DEBUG, "PermissionService");
        }

        $binDir = "/usr/local/emhttp/plugins/unraid-aicliagents/bin";
        if (is_dir($binDir)) {
            exec("chmod +x $binDir/* > /dev/null 2>&1");
        }
    }
}
