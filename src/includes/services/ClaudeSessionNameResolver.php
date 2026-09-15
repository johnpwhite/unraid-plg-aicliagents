<?php
/**
 * ClaudeSessionNameResolver — turn a Claude Code session NAME back into its id.
 *
 * docs/specs/CLAUDE_RESUME_BY_SESSION_ID.md (Forgejo #221). When a Claude session has a
 * name, its exit line prints `claude --resume "<name>"` instead of the session UUID, and
 * names are not unique: resuming by name makes Claude ask which session was meant. Claude
 * records each conversation's name inside its own file, so the name can be mapped back:
 *
 *   ~/.claude/projects/<folder key>/<uuid>.jsonl
 *   {"type":"custom-title","customTitle":"AI CLI Agents","sessionId":"<uuid>"}
 *
 * The LAST such line in a file is that conversation's current name.
 */

namespace AICliAgents\Services;

class ClaudeSessionNameResolver
{
    public const AGENT_ID = 'claude-code';

    /**
     * Two same-named conversations in one folder: the previous id this workspace owned
     * still wins when its file was written this close to the newest one.
     */
    public const TIE_WINDOW_SECS = 120;

    /** Test seam: the HOME that holds `.claude/projects`. Null = the plugin user's home. */
    public static ?string $homeOverride = null;

    /** True for a Claude session id (a UUID). */
    public static function isSessionId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /**
     * The id to save or resume with. Returns $chatId unchanged unless it is a Claude
     * session name that exactly one conversation (or a clear newest one) in this folder
     * carries. Never throws: any failure keeps the name, which is today's behaviour.
     *
     * $sharedFolder: another open workspace uses this folder with this agent. Then
     * "newest" among several same-named conversations may be THAT workspace's, and
     * silently resuming someone else's conversation is worse than Claude asking — so
     * the name is kept unless $previousId settles it.
     */
    public static function resolve(string $agentId, string $path, string $chatId, ?string $previousId = null, bool $sharedFolder = false): string
    {
        if ($agentId !== self::AGENT_ID || $path === '' || $chatId === ''
            || $chatId === '_fresh_' || $chatId === 'auto' || $chatId === 'none' || self::isSessionId($chatId)) {
            return $chatId;
        }
        try {
            $candidates = self::conversationsNamed($path, $chatId);
            if ($candidates === []) return $chatId;

            arsort($candidates);                       // newest write first
            $newestId = (string)array_key_first($candidates);
            $newestAt = (int)reset($candidates);
            $chosen = $newestId;
            if ($previousId !== null && isset($candidates[$previousId])
                && $newestAt - (int)$candidates[$previousId] <= self::TIE_WINDOW_SECS) {
                $chosen = $previousId;
            } elseif (count($candidates) > 1 && $sharedFolder) {
                return $chatId;
            }
            if (class_exists(LogService::class)) {
                LogService::log("Claude session name \"$chatId\" resolved to $chosen (" . count($candidates)
                    . " conversation(s) in this folder carry that name)", LogService::LOG_INFO, 'ClaudeSessionNameResolver');
            }
            return $chosen;
        } catch (\Throwable $e) {
            return $chatId;
        }
    }

    /**
     * Conversations in this folder's project store whose current name is $name.
     *
     * @return array<string,int> session id => file modification time
     */
    public static function conversationsNamed(string $path, string $name): array
    {
        $found = [];
        foreach (self::projectDirs($path) as $dir) {
            foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
                $base = basename($file, '.jsonl');
                // Subagent transcripts are not resumable conversations.
                if (strncmp($base, 'agent-', 6) === 0 || !self::isSessionId($base)) continue;
                if (self::currentTitle($file) === $name) {
                    $found[$base] = (int)@filemtime($file);
                }
            }
        }
        return $found;
    }

    /** The last `custom-title` a conversation file records, or null when it has none. */
    public static function currentTitle(string $file): ?string
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) return null;
        $title = null;
        try {
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, '"custom-title"') === false) continue;
                $row = json_decode($line, true);
                if (is_array($row) && ($row['type'] ?? '') === 'custom-title' && is_string($row['customTitle'] ?? null)) {
                    $title = $row['customTitle'];
                }
            }
        } finally {
            fclose($fh);
        }
        return $title;
    }

    /**
     * Claude's folder key: every character that is not a letter or digit becomes '-'.
     * aicli-shell.sh's own discovery replaces only '/', so that form is tried too.
     */
    public static function projectKeys(string $dir): array
    {
        return array_values(array_unique([
            (string)preg_replace('/[^A-Za-z0-9]/', '-', $dir),
            '-' . str_replace('/', '-', ltrim($dir, '/')),
        ]));
    }

    /** Existing project store directories for a workspace, including the pool path it may run in. */
    private static function projectDirs(string $path): array
    {
        $home = self::$homeOverride;
        if ($home === null) {
            $user = 'root';
            try { $user = (string)((ConfigService::getConfig()['user'] ?? 'root') ?: 'root'); } catch (\Throwable $e) {}
            $home = UtilityService::getWorkDir($user) . '/home';
        }
        $roots = [$path];
        try {
            if (class_exists(PoolPathService::class)) {
                $roots[] = PoolPathService::launchDirectory($path, ConfigService::getConfig());
            }
        } catch (\Throwable $e) {
            // The workspace path alone is still a valid place to look.
        }
        $dirs = [];
        foreach (array_unique($roots) as $root) {
            foreach (self::projectKeys(rtrim($root, '/')) as $key) {
                $dir = $home . '/.claude/projects/' . $key;
                if (is_dir($dir)) $dirs[$dir] = true;
            }
        }
        return array_keys($dirs);
    }
}
