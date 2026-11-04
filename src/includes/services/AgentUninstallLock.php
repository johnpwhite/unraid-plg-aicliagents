<?php
/**
 * <module_context>
 *     <name>AgentUninstallLock</name>
 *     <description>One lock for each agent. An uninstall holds it exclusively from before the version scan until the purge ends. Every workspace start of that agent holds it shared while it starts. So a start cannot bind a version again while the uninstall removes it (Forgejo #906). See docs/specs/AGENT_UNINSTALL_PURGE_STORAGE.md.</description>
 *     <dependencies>SecretPaths, SessionLaunchLock (for the folder).</dependencies>
 *     <constraints>Static methods only. Never throws. The lock is a flock(2) on a tmpfs file, taken NON-BLOCKING with no wait: a caller that does not get it refuses at once with a plain reason. The kernel frees the lock when the holder ends or crashes, so a stale mark cannot exist and cannot block a start for ever. The file is opened close-on-exec, so a tmux server or a ttyd that the holder starts never inherits it.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AgentUninstallLock
{
    /** Lock file of an agent, or null when the id is empty after cleaning. */
    public static function path(string $agentId): ?string
    {
        $safe = (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId);
        return $safe === '' ? null : SessionLaunchLock::dir() . '/agent-uninstall/' . $safe . '.lock';
    }

    /** @return resource|false */
    private static function take(string $agentId, int $mode)
    {
        $path = self::path($agentId);
        if ($path === null) return false;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
        $fh = @fopen($path, 'ce');   // 'c' creates without truncating; 'e' sets close-on-exec.
        if ($fh === false) return false;
        if (!@flock($fh, $mode | LOCK_NB)) { @fclose($fh); return false; }
        return $fh;
    }

    /**
     * The uninstall takes the lock exclusively. False when a start of this agent
     * holds it, or another uninstall does.
     * @return resource|false
     */
    public static function acquireForUninstall(string $agentId)
    {
        return self::take($agentId, LOCK_EX);
    }

    /**
     * A start takes the lock shared. False while an uninstall of this agent runs.
     * @return resource|false
     */
    public static function acquireForStart(string $agentId)
    {
        return self::take($agentId, LOCK_SH);
    }

    /** @param resource|false|null $lock */
    public static function release($lock): void
    {
        if (!is_resource($lock)) return;
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}
