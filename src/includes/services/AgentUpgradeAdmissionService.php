<?php
/**
 * Per-agent start/install admission lock for issue #71.
 *
 * The lock lives on tmpfs, never /mnt/user, and is always attempted with
 * LOCK_NB. Terminal registration and the upgrade start barrier therefore
 * cannot cross in flight even when the initial status check races.
 */

namespace AICliAgents\Services;

class AgentUpgradeAdmissionService
{
    /** @return resource|null */
    public static function acquire(string $agentId)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $agentId)) return null;
        $tmpBase = getenv('AICLI_TMP_BASE');
        $base = rtrim($tmpBase !== false && $tmpBase !== '' ? $tmpBase : SecretPaths::stateRoot(), '/');
        @mkdir($base, 0755, true);
        $handle = @fopen("$base/agent-upgrade-admission-$agentId.lock", 'ce');   // 'e': close-on-exec, so a tmux server started by the holder never keeps the lock
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    public static function release($handle): void
    {
        if (!is_resource($handle)) return;
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
