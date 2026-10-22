<?php
/**
 * <module_context>
 * Description: Where each registered agent keeps its conversation history in a
 *   home, how to measure it, and which sessions are old enough to move
 *   (docs/specs/BOOT_DISK_SPACE.md R6, epic #534, issue #539).
 *   stores() is the table: one entry for each agent in
 *   AgentRegistry::getDefaultAgents(). measure() reports the size per agent.
 *   selectOld() lists the files of the sessions older than N days.
 * Dependencies: none. Pure file system code. It does not load the plugin config.
 * Constraints: Read-only. This class never writes, moves or removes a file.
 *   A new agent MUST get an entry here: RegressionGuardsTest-style guard
 *   HistoryStoreServiceTest::testEveryRegisteredAgentHasAHistoryDecision fails
 *   until it has one.
 * </module_context>
 *
 * Words used here:
 *  - A "session" is one conversation. It can be one file, or a few files that
 *    belong together (a Claude transcript and its sub-agent folder).
 *  - A "unit" is the set of files of one session. The key of a unit is capture
 *    group 1 of the store's pattern. A unit is as old as its newest file.
 *  - kind "files": one file or folder for each session. The sessions can move.
 *  - kind "database": the history is inside a database. It cannot move. The
 *    size is shown, with the reason.
 *  - kind "unknown": the layout is not known. It is shown, never moved.
 */

namespace AICliAgents\Services;

class HistoryStoreService
{
    /** A file changed within this many seconds is never selected. */
    public const RECENT_GUARD_SECONDS = 86400;

    /** measure(): stop after this many seconds and report a partial result. */
    public const MEASURE_BUDGET_SECONDS = 25;

    /** Ages (days) that measure() reports by default. */
    public const DEFAULT_AGES = [30, 90, 180];

    /**
     * The table. Paths are relative to the home folder. 'pattern' is a regular
     * expression for the path of one file of a session, relative to the home.
     * Capture group 1 is the key of the unit.
     * 'index' lists glob patterns (relative to the home) of index or database
     * files that belong to the history but do not move; they are only counted.
     * 'verified' is true when the layout was seen on a real home and is stated
     * by the agent's official documentation or source (2026-10-16, prod02).
     *
     * @return array<string,array{label:string,kind:string,roots:array<int,string>,pattern:string,index:array<int,string>,source:string,note:string,verified:bool}>
     */
    public static function stores(): array
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        return [
            // Claude Code: one transcript file per session in the folder of its
            // working directory, plus a folder with the same id for sub-agent
            // and tool output; checkpoints in file-history/<id>.
            // https://code.claude.com/docs/en/claude-directory
            'claude-code' => [
                'label'   => 'Claude Code',
                'kind'    => 'files',
                'roots'   => ['.claude/projects', '.claude/file-history'],
                'pattern' => '#^\.claude/(?:projects/[^/]+|file-history)/(' . $uuid . ')(?:\.[^/]*|/.+)$#',
                'index'   => ['.claude/history.jsonl'],
                'source'  => 'https://code.claude.com/docs/en/claude-directory',
                'note'    => 'One transcript file for each session. Resume reads the newest file of the working folder.',
                'verified' => true,
            ],
            // Codex: one rollout file per session in a date tree. State and
            // thread history are SQLite files that stay.
            // https://github.com/openai/codex/blob/main/codex-rs/rollout/src/lib.rs
            'codex-cli' => [
                'label'   => 'Codex CLI',
                'kind'    => 'files',
                'roots'   => ['.codex/sessions'],
                'pattern' => '#^\.codex/sessions/\d{4}/\d{2}/\d{2}/(rollout-[^/]+?)\.jsonl(?:\.zst)?$#D',
                'index'   => ['.codex/*.sqlite', '.codex/*.sqlite-wal', '.codex/*.sqlite-shm', '.codex/session_index.jsonl', '.codex/history.jsonl'],
                'source'  => 'https://github.com/openai/codex/blob/main/codex-rs/rollout/src/lib.rs',
                'note'    => 'One rollout file for each session. A moved session stays listed in the Codex index but cannot open. The SQLite index files stay.',
                'verified' => true,
            ],
            // Gemini CLI: ~/.gemini/tmp/<project>/chats/.
            // https://geminicli.com/docs/cli/session-management/
            'gemini-cli' => [
                'label'   => 'Gemini CLI',
                'kind'    => 'files',
                'roots'   => ['.gemini/tmp'],
                'pattern' => '#^\.gemini/tmp/([^/]+/chats/session-[^/]+?)\.jsonl?$#D',
                'index'   => [],
                'source'  => 'https://geminicli.com/docs/cli/session-management/',
                'note'    => 'One chat file for each session. Gemini also deletes its own old sessions (general.sessionRetention).',
                'verified' => true,
            ],
            // OpenCode: one SQLite database (opencode.db). The plugin keeps one
            // more database for each workspace under aicli-opencode.
            // https://opencode.ai/docs/ (no page names the path; layout seen on disk)
            'opencode' => [
                'label'   => 'OpenCode',
                'kind'    => 'database',
                'roots'   => ['.local/share/opencode', '.local/share/aicli-opencode'],
                'pattern' => '',
                'index'   => [],
                'source'  => 'https://github.com/sst/opencode (packages/opencode/src/storage)',
                'note'    => 'The history is inside SQLite databases, not one file for each session. It cannot move.',
                'verified' => false,
            ],
            // Kilo Code: a fork of OpenCode. One SQLite database, kilo.db.
            // https://github.com/Kilo-Org/kilocode/blob/main/packages/opencode/src/storage/db.ts
            'kilocode' => [
                'label'   => 'Kilo Code',
                'kind'    => 'database',
                'roots'   => ['.local/share/kilo'],
                'pattern' => '',
                'index'   => [],
                'source'  => 'https://github.com/Kilo-Org/kilocode/blob/main/packages/opencode/src/storage/db.ts',
                'note'    => 'The history is inside the SQLite database kilo.db. It cannot move.',
                'verified' => true,
            ],
            // Pi: ~/.pi/agent/sessions/--<cwd>--/<timestamp>_<id>.jsonl.
            // https://pi.dev/docs/latest/sessions
            'pi-coder' => [
                'label'   => 'Pi Coder',
                'kind'    => 'files',
                'roots'   => ['.pi/agent/sessions'],
                'pattern' => '#^\.pi/agent/sessions/(--[^/]+--/[^/]+?)\.jsonl$#D',
                'index'   => [],
                'source'  => 'https://pi.dev/docs/latest/sessions',
                'note'    => 'One JSONL file for each session, in a folder named after the working folder.',
                'verified' => false,
            ],
            // GitHub Copilot CLI: a folder for each session in session-state;
            // session-store.db is a searchable subset (an index).
            // https://docs.github.com/en/copilot/concepts/agents/copilot-cli/chronicle
            'gh-copilot' => [
                'label'   => 'GitHub Copilot',
                'kind'    => 'files',
                'roots'   => ['.copilot/session-state'],
                'pattern' => '#^\.copilot/session-state/(' . $uuid . ')/.+$#',
                'index'   => ['.copilot/session-store.db', '.copilot/session-store.db-wal', '.copilot/session-store.db-shm'],
                'source'  => 'https://docs.github.com/en/copilot/concepts/agents/copilot-cli/chronicle',
                'note'    => 'One folder for each session. The search index session-store.db stays and may list a moved session that cannot open.',
                'verified' => true,
            ],
            // Factory Droid: ~/.factory/sessions. The official pages name the
            // resume flags only, and the folder is empty on the test server.
            // https://docs.factory.com/droid-cli/cli-reference
            'factory-cli' => [
                'label'   => 'Factory CLI',
                'kind'    => 'files',
                'roots'   => ['.factory/sessions'],
                'pattern' => '#^\.factory/sessions/(.+?)(?:\.[A-Za-z]+)*$#D',
                'index'   => [],
                'source'  => 'https://docs.factory.com/droid-cli/cli-reference',
                'note'    => 'The files of one session share one name. The layout is not seen on this server yet (the folder is empty).',
                'verified' => false,
            ],
            // NanoCoder: sessions.json is the index; one <id>.json for each session.
            // https://github.com/Nano-Collective/nanocoder/blob/main/source/session/session-manager.ts
            'nanocoder' => [
                'label'   => 'NanoCoder',
                'kind'    => 'files',
                'roots'   => ['.local/share/nanocoder/sessions'],
                'pattern' => '#^\.local/share/nanocoder/sessions/(?!sessions\.json$)([^/]+?)\.json$#D',
                'index'   => ['.local/share/nanocoder/sessions/sessions.json'],
                'source'  => 'https://github.com/Nano-Collective/nanocoder/blob/main/source/session/session-manager.ts',
                'note'    => 'One JSON file for each session. The index sessions.json stays. The layout is not seen on this server yet.',
                'verified' => false,
            ],
            // Goose: SQLite database sessions.db (since v1.10.0).
            // https://github.com/block/goose/blob/main/documentation/docs/guides/sessions/session-management.md
            'goose' => [
                'label'   => 'Goose',
                'kind'    => 'database',
                'roots'   => ['.local/share/goose/sessions'],
                'pattern' => '',
                'index'   => [],
                'source'  => 'https://github.com/block/goose/blob/main/documentation/docs/guides/sessions/session-management.md',
                'note'    => 'The history is inside the SQLite database sessions.db. It cannot move.',
                'verified' => true,
            ],
            // Qwen Code: <id>.jsonl (and <id>.runtime.json) in a chats folder.
            // The source says ~/.qwen/tmp/<project>/chats; the version on the
            // test server writes ~/.qwen/projects/<project>/chats.
            // https://github.com/QwenLM/qwen-code/blob/main/packages/core/src/services/chatRecordingService.ts
            'qwen-code' => [
                'label'   => 'Qwen Code',
                'kind'    => 'files',
                'roots'   => ['.qwen/projects', '.qwen/tmp'],
                'pattern' => '#^\.qwen/(?:projects|tmp)/([^/]+/chats/' . $uuid . ')\.(?:jsonl|runtime\.json)$#',
                'index'   => [],
                'source'  => 'https://github.com/QwenLM/qwen-code/blob/main/packages/core/src/services/chatRecordingService.ts',
                'note'    => 'One chat file for each session in the chats folder of its project.',
                'verified' => true,
            ],
            // Antigravity CLI: conversations/<id>.pb or <id>.db (+ -wal, -shm)
            // and brain/<id>/. The index cache/last_conversations.json stays.
            // https://www.antigravity.google/docs/cli/commands/resume
            'antigravity-cli' => [
                'label'   => 'Antigravity CLI',
                'kind'    => 'files',
                'roots'   => ['.gemini/antigravity-cli/conversations', '.gemini/antigravity-cli/brain'],
                'pattern' => '#^\.gemini/antigravity-cli/(?|conversations/(' . $uuid . ')\.(?:pb|db)(?:-wal|-shm)?|brain/(' . $uuid . ')/.+)$#',
                'index'   => ['.gemini/antigravity-cli/conversation_summaries.db*', '.gemini/antigravity-cli/cache/last_conversations.json'],
                'source'  => 'https://www.antigravity.google/docs/cli/commands/resume',
                'note'    => 'One conversation file (.pb or SQLite .db with its -wal and -shm files) and one brain folder for each session. They move together.',
                'verified' => true,
            ],
            // Grok Build: ~/.grok/sessions/<working folder, URL-encoded>/<id>/.
            // session_search.sqlite is a search index; resume falls back to a scan.
            // https://docs.x.ai/build/features/sessions
            'grok-build' => [
                'label'   => 'Grok Build',
                'kind'    => 'files',
                'roots'   => ['.grok/sessions'],
                'pattern' => '#^\.grok/sessions/([^/]+/[^/]+)/.+$#D',
                'index'   => ['.grok/sessions/session_search.sqlite*'],
                'source'  => 'https://docs.x.ai/build/features/sessions',
                'note'    => 'One folder for each session. The search index stays.',
                'verified' => true,
            ],
            // Kimi Code: $KIMI_CODE_HOME/sessions/<wd_slug_hash>/<session_id>/ with
            // state.json and agents/*/wire.jsonl; session_index.jsonl is the index.
            // https://www.kimi.com/code/docs/en/kimi-code-cli/configuration/data-locations.html
            'kimi-code' => [
                'label'   => 'Kimi Code',
                'kind'    => 'files',
                'roots'   => ['.kimi-code/sessions'],
                'pattern' => '#^\.kimi-code/sessions/([^/]+/session_[^/]+)/.+$#D',
                'index'   => ['.kimi-code/session_index.jsonl'],
                'source'  => 'https://www.kimi.com/code/docs/en/kimi-code-cli/configuration/data-locations.html',
                'note'    => 'One folder for each session. The index session_index.jsonl stays.',
                'verified' => true,
            ],
        ];
    }

    /** Can the sessions of this agent move? */
    public static function isMovable(string $agentId): bool
    {
        $s = self::stores();
        return isset($s[$agentId]) && $s[$agentId]['kind'] === 'files';
    }

    /**
     * The unit key of a home-relative path for an agent, or null when the path
     * is not a session file of that agent.
     */
    public static function unitKey(string $agentId, string $rel): ?string
    {
        $s = self::stores();
        if (!isset($s[$agentId]) || $s[$agentId]['pattern'] === '') return null;
        if (@preg_match($s[$agentId]['pattern'], $rel, $m) !== 1) return null;
        return isset($m[1]) && $m[1] !== '' ? (string)$m[1] : null;
    }

    /**
     * Size and age figures per agent. Allocated bytes (compression counted).
     *
     * @param array<int,int> $ages Ages in days for 'older_than'.
     * @return array<string,array<string,mixed>> agentId => [
     *   'bytes'=>int, 'files'=>int, 'sessions'=>int, 'index_bytes'=>int,
     *   'older_than'=>[days=>['files'=>int,'bytes'=>int,'sessions'=>int]], 'partial'=>bool ]
     *   'bytes' counts the sessions of a files store, or the whole database
     *   folders of a database store.
     */
    public static function measure(string $homeDir, ?array $ages = null, ?int $now = null, ?int $budgetSeconds = null): array
    {
        $ages = $ages ?? self::DEFAULT_AGES;
        $now = $now ?? time();
        $deadline = microtime(true) + ($budgetSeconds ?? self::MEASURE_BUDGET_SECONDS);
        $out = [];
        foreach (self::stores() as $agentId => $store) {
            $row = ['bytes' => 0, 'files' => 0, 'sessions' => 0, 'index_bytes' => 0,
                    'older_than' => [], 'partial' => false];
            foreach ($ages as $d) $row['older_than'][(int)$d] = ['files' => 0, 'bytes' => 0, 'sessions' => 0];

            if ($store['kind'] === 'files') {
                $units = self::scanUnits($homeDir, $agentId, $deadline, $partial);
                $row['partial'] = $partial;
                foreach ($units as $u) {
                    $row['bytes'] += $u['bytes'];
                    $row['files'] += count($u['files']);
                    $row['sessions']++;
                    foreach ($ages as $d) {
                        if ($u['mtime'] <= self::cutoff((int)$d, $now)) {
                            $row['older_than'][(int)$d]['files'] += count($u['files']);
                            $row['older_than'][(int)$d]['bytes'] += $u['bytes'];
                            $row['older_than'][(int)$d]['sessions']++;
                        }
                    }
                }
            } else {
                foreach ($store['roots'] as $root) {
                    $t = self::treeSize($homeDir . '/' . $root, $deadline, $partial2);
                    $row['bytes'] += $t['bytes'];
                    $row['files'] += $t['files'];
                    if ($partial2) $row['partial'] = true;
                }
            }
            $row['index_bytes'] = self::indexBytes($homeDir, $store['index']);
            $out[$agentId] = $row;
        }
        return $out;
    }

    /**
     * Relative paths (to the home) of every file of the sessions that are
     * older than $days, oldest session first. Skips a session when any of its
     * files changed in the last 24 hours, or when a process holds one open.
     * Returns [] for an agent that is not kind "files".
     *
     * @param null|callable $openProvider fn(string $homeDir): array<int,string> absolute paths of open files
     * @return array<int,string>
     */
    public static function selectOld(string $homeDir, string $agentId, int $days, ?callable $openProvider = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $s = self::stores();
        if (!isset($s[$agentId]) || $s[$agentId]['kind'] !== 'files') return [];
        $cutoff = min(self::cutoff($days, $now), $now - self::RECENT_GUARD_SECONDS);
        $open = self::openSet($homeDir, $openProvider);
        $units = self::scanUnits($homeDir, $agentId, microtime(true) + 3600, $partial);
        $picked = [];
        foreach ($units as $u) {
            if ($u['mtime'] > $cutoff) continue;
            $busy = false;
            foreach ($u['files'] as $f) {
                if (isset($open[$f['abs']])) { $busy = true; break; }
            }
            if ($busy) continue;
            $picked[] = $u;
        }
        usort($picked, static function ($a, $b) {
            return [$a['mtime'], $a['key']] <=> [$b['mtime'], $b['key']];
        });
        $paths = [];
        foreach ($picked as $u) {
            $rels = array_map(static function ($f) { return $f['rel']; }, $u['files']);
            sort($rels);
            foreach ($rels as $r) $paths[] = $r;
        }
        return $paths;
    }

    /**
     * Count and size of what selectOld() would pick, per agent. Used by the
     * dialog before anything moves.
     *
     * @param array<int,string> $agentIds
     * @return array{files:int,bytes:int,sessions:int,agents:array<string,array{files:int,bytes:int,sessions:int}>}
     */
    public static function summarize(string $homeDir, array $agentIds, int $days, ?callable $openProvider = null, ?int $now = null): array
    {
        $total = ['files' => 0, 'bytes' => 0, 'sessions' => 0, 'agents' => []];
        foreach ($agentIds as $agentId) {
            $paths = self::selectOld($homeDir, $agentId, $days, $openProvider, $now);
            $keys = [];
            $bytes = 0;
            foreach ($paths as $rel) {
                $k = self::unitKey($agentId, $rel);
                if ($k !== null) $keys[$k] = true;
                $bytes += self::allocated($homeDir . '/' . $rel);
            }
            $total['agents'][$agentId] = ['files' => count($paths), 'bytes' => $bytes, 'sessions' => count($keys)];
            $total['files'] += count($paths);
            $total['bytes'] += $bytes;
            $total['sessions'] += count($keys);
        }
        return $total;
    }

    // ---- internals -------------------------------------------------------

    private static function cutoff(int $days, int $now): int
    {
        return $now - max(0, $days) * 86400;
    }

    /** Allocated bytes of one path (blocks x 512), or the size when blocks are unknown. */
    public static function allocated(string $abs): int
    {
        $st = @lstat($abs);
        if ($st === false) return 0;
        $blocks = (int)($st['blocks'] ?? -1);
        return $blocks >= 0 ? $blocks * 512 : (int)$st['size'];
    }

    /**
     * Absolute paths of the files a process holds open under the home.
     * @return array<string,bool>
     */
    private static function openSet(string $homeDir, ?callable $provider): array
    {
        $paths = $provider !== null ? (array)$provider($homeDir) : self::procOpenFiles($homeDir);
        $set = [];
        foreach ($paths as $p) $set[(string)$p] = true;
        return $set;
    }

    /**
     * Scan /proc/<pid>/fd for links that point under the home.
     * @return array<int,string>
     */
    public static function procOpenFiles(string $homeDir): array
    {
        $prefixes = [rtrim($homeDir, '/') . '/'];
        $real = @realpath($homeDir);
        if ($real !== false) $prefixes[] = rtrim($real, '/') . '/';
        $found = [];
        foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fd) {
            $target = @readlink($fd);
            if ($target === false) continue;
            foreach ($prefixes as $pre) {
                if (strncmp($target, $pre, strlen($pre)) === 0) { $found[$target] = true; break; }
            }
        }
        $paths = array_keys($found);
        // Also report the form with the other prefix, so the lookup works either way.
        if (count($prefixes) === 2 && $prefixes[0] !== $prefixes[1]) {
            foreach ($paths as $p) {
                if (strncmp($p, $prefixes[1], strlen($prefixes[1])) === 0) {
                    $paths[] = $prefixes[0] . substr($p, strlen($prefixes[1]));
                }
            }
        }
        return $paths;
    }

    /**
     * Walk the roots of a files store and group the session files into units.
     * Symlinks are skipped, so a link can never lead out of the home.
     *
     * @param bool $partial set to true when the deadline stopped the scan
     * @return array<string,array{key:string,mtime:int,bytes:int,files:array<int,array{rel:string,abs:string}>}>
     */
    private static function scanUnits(string $homeDir, string $agentId, float $deadline, &$partial): array
    {
        $partial = false;
        $store = self::stores()[$agentId];
        $home = rtrim($homeDir, '/');
        $units = [];
        $n = 0;
        foreach ($store['roots'] as $root) {
            $base = $home . '/' . $root;
            if (!is_dir($base) || is_link($base)) continue;
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                        static function ($cur) { return !$cur->isLink(); }
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($it as $f) {
                if ((++$n & 255) === 0 && microtime(true) > $deadline) { $partial = true; break 2; }
                if (!$f->isFile()) continue;
                $abs = $f->getPathname();
                $rel = substr($abs, strlen($home) + 1);
                $key = self::unitKey($agentId, $rel);
                if ($key === null) continue;
                $st = @lstat($abs);
                if ($st === false) continue;
                $blocks = (int)($st['blocks'] ?? -1);
                $bytes = $blocks >= 0 ? $blocks * 512 : (int)$st['size'];
                if (!isset($units[$key])) $units[$key] = ['key' => $key, 'mtime' => 0, 'bytes' => 0, 'files' => []];
                $units[$key]['mtime'] = max($units[$key]['mtime'], (int)$st['mtime']);
                $units[$key]['bytes'] += $bytes;
                $units[$key]['files'][] = ['rel' => $rel, 'abs' => $abs];
            }
        }
        return $units;
    }

    /** @return array{bytes:int,files:int} */
    private static function treeSize(string $base, float $deadline, &$partial): array
    {
        $partial = false;
        $t = ['bytes' => 0, 'files' => 0];
        if (!is_dir($base) || is_link($base)) return $t;
        $n = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    static function ($cur) { return !$cur->isLink(); }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                if ((++$n & 255) === 0 && microtime(true) > $deadline) { $partial = true; break; }
                if (!$f->isFile()) continue;
                $t['bytes'] += self::allocated($f->getPathname());
                $t['files']++;
            }
        } catch (\Throwable $e) {
            // An unreadable folder counts as empty.
        }
        return $t;
    }

    /** @param array<int,string> $globs */
    private static function indexBytes(string $homeDir, array $globs): int
    {
        $bytes = 0;
        $seen = [];
        foreach ($globs as $g) {
            foreach (glob(rtrim($homeDir, '/') . '/' . $g) ?: [] as $p) {
                if (isset($seen[$p]) || is_link($p) || !is_file($p)) continue;
                $seen[$p] = true;
                $bytes += self::allocated($p);
            }
        }
        return $bytes;
    }
}
