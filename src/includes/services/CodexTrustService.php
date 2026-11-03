<?php
/**
 * <module_context>
 *     <name>CodexTrustService</name>
 *     <description>Pre-trust of a helper workspace's worktree for Codex CLI (docs/specs/AGENT_SPAWN_WORKSPACE.md R20).
 *     Codex asks "Trust this folder?" the first time it opens a folder. A helper has no person at the keyboard, so
 *     the spawn tool adds the table `[projects."<worktree>"]` with `trust_level = "trusted"` to
 *     `~/.codex/config.toml` of the overlay home before the helper starts, and removes it when the helper closes.</description>
 *     <dependencies>HubProjector (home path), LogService</dependencies>
 *     <constraints>The text edit is a pure function and changes only the one table: every other line stays
 *     byte-identical (line endings included). It is line based, as the Config Hub fence editor is, so a line
 *     that starts a table header inside a multi-line string is not understood. A path that is not a worktree
 *     made by the spawn tool is refused (isToolMadeWorktree). The plugin never writes ~/.codex/auth.json. A
 *     table with other keys than trust_level keeps those keys when the trust is removed.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

use AICliAgents\Services\Hub\HubProjector;

class CodexTrustService
{
    public const TRUST_LINE = 'trust_level = "trusted"';

    /** Test seam: the overlay home to use instead of the real one. */
    public static ?string $homeForTests = null;

    // ======================================================================
    // The path rules
    // ======================================================================

    /** An absolute path of plain text: no control character, no ".." part, at most 1000 characters. */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > 1000) return false;
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) return false;
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') return false;
        }
        return true;
    }

    /**
     * R20: only a worktree that the spawn tool made under `<repo>/<worktree folder>/` is trusted (default `.aicli/worktrees`, plus the legacy `.claude/worktrees`). `$wt` is the
     * `worktree` block of the helper's `spawn` data.
     *
     * @param array<string,mixed>|null $wt
     */
    public static function isToolMadeWorktree(?array $wt, string $path): bool
    {
        if ($wt === null || empty($wt['tool_made']) || !self::isSafePath($path)) return false;
        $repo = rtrim((string)($wt['repo'] ?? ''), '/');
        if ((string)($wt['path'] ?? '') !== $path || !self::isSafePath($repo) || $repo === '') return false;
        // #865 item 2: the default folder, a folder that a profile names, and the legacy `.claude/worktrees` all count.
        foreach (AgentLaunchProfile::recognisedWorktreeDirs() as $dir) {
            $prefix = $repo . '/' . $dir . '/';
            if (strpos($path, $prefix) !== 0) continue;
            $rest = substr($path, strlen($prefix));
            if ($rest !== '' && strpos($rest, '/') === false) return true;
        }
        return false;
    }

    // ======================================================================
    // The pure text edit
    // ======================================================================

    /** The header line of the table, in the basic-string form that Codex writes. */
    public static function header(string $path): string
    {
        return '[projects."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"]';
    }

    private static function headerRegex(string $path): string
    {
        $basic = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
        $alts = [preg_quote($basic, '/')];
        if (strpos($path, "'") === false) $alts[] = preg_quote("'" . $path . "'", '/');
        return '/^[ \t]*\[[ \t]*projects[ \t]*\.[ \t]*(?:' . implode('|', $alts) . ')[ \t]*\][ \t]*(?:#.*)?$/';
    }

    /** True for a line that starts a table: a key path in brackets, not an array value. */
    private static function isHeaderLine(string $line): bool
    {
        return preg_match('/^[ \t]*\[\[?[ \t]*[A-Za-z0-9_\-"\'.\\\\\/ :~@+]+[ \t]*\]\]?[ \t]*(?:#.*)?$/D', rtrim($line, "\r\n")) === 1;
    }

    /** @return list<string> the lines, each with its own line ending */
    private static function lines(string $text): array
    {
        return $text === '' ? [] : (preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return array{0:int,1:int}|null [header line index, index of the first line after the table] */
    private static function findTable(array $lines, string $path): ?array
    {
        $re = self::headerRegex($path);
        foreach ($lines as $i => $line) {
            if (preg_match($re, rtrim($line, "\r\n"))) {
                $end = count($lines);
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (self::isHeaderLine($lines[$j])) { $end = $j; break; }
                }
                return [$i, $end];
            }
        }
        return null;
    }

    /**
     * Return the text with the table `[projects."<path>"]` set to `trust_level = "trusted"`. A missing table is
     * appended at the end. A table that has another trust_level gets that one line changed. Nothing else changes.
     *
     * @return array{text:string,changed:bool}
     */
    public static function addTrust(string $text, string $path): array
    {
        if (!self::isSafePath($path)) return ['text' => $text, 'changed' => false];
        $lines = self::lines($text);
        $found = self::findTable($lines, $path);
        if ($found === null) {
            $out = $text;
            if ($out !== '' && substr($out, -1) !== "\n") $out .= "\n";
            if ($out !== '') $out .= "\n";
            return ['text' => $out . self::header($path) . "\n" . self::TRUST_LINE . "\n", 'changed' => true];
        }
        [$h, $end] = $found;
        for ($k = $h + 1; $k < $end; $k++) {
            if (preg_match('/^[ \t]*trust_level[ \t]*=/', $lines[$k])) {
                if (preg_match('/^[ \t]*trust_level[ \t]*=[ \t]*"trusted"[ \t]*(?:#.*)?\r?\n?$/D', $lines[$k])) {
                    return ['text' => $text, 'changed' => false];
                }
                $eol = substr($lines[$k], strlen(rtrim($lines[$k], "\r\n")));
                $lines[$k] = self::TRUST_LINE . ($eol !== '' ? $eol : "\n");
                return ['text' => implode('', $lines), 'changed' => true];
            }
        }
        // The table has no trust_level: add one right after the header line.
        if (substr($lines[$h], -1) !== "\n") $lines[$h] .= "\n";
        array_splice($lines, $h + 1, 0, [self::TRUST_LINE . "\n"]);
        return ['text' => implode('', $lines), 'changed' => true];
    }

    /**
     * Return the text without the trust of this path. A table that holds nothing but trust_level is removed
     * (with the blank line before it, when the table was added at the end). A table with other keys or comments
     * keeps them and loses only the trust_level line.
     *
     * @return array{text:string,changed:bool}
     */
    public static function removeTrust(string $text, string $path): array
    {
        if (!self::isSafePath($path)) return ['text' => $text, 'changed' => false];
        $lines = self::lines($text);
        $found = self::findTable($lines, $path);
        if ($found === null) return ['text' => $text, 'changed' => false];
        [$h, $end] = $found;
        $keep = [];
        $dropped = false;
        for ($k = $h + 1; $k < $end; $k++) {
            if (preg_match('/^[ \t]*trust_level[ \t]*=/', $lines[$k])) { $dropped = true; continue; }
            $keep[] = $k;
        }
        if (!$dropped) return ['text' => $text, 'changed' => false];   // no trust_level here: the table is not ours to touch
        $onlyBlank = true;
        foreach ($keep as $k) {
            if (trim($lines[$k]) !== '') { $onlyBlank = false; break; }
        }
        if ($onlyBlank) {
            // Remove the whole table. A blank line before it goes too when the table ends the file.
            $from = $h;
            if ($end === count($lines) && $h > 0 && trim($lines[$h - 1]) === '') $from = $h - 1;
            array_splice($lines, $from, $end - $from);
            return ['text' => implode('', $lines), 'changed' => true];
        }
        foreach (array_reverse(array_keys($lines)) as $k) {
            if ($k > $h && $k < $end && preg_match('/^[ \t]*trust_level[ \t]*=/', $lines[$k])) array_splice($lines, $k, 1);
        }
        return ['text' => implode('', $lines), 'changed' => true];
    }

    // ======================================================================
    // The file
    // ======================================================================

    /** @return array{ok:true,file:string,home:string}|array{ok:false,reason:string} */
    private static function configFile(): array
    {
        if (self::$homeForTests !== null) {
            $home = rtrim(self::$homeForTests, '/');
        } else {
            $resolved = HubProjector::resolveHome();
            if (empty($resolved['ok'])) return ['ok' => false, 'reason' => 'home_unavailable'];
            $home = rtrim((string)$resolved['home'], '/');
        }
        return ['ok' => true, 'file' => $home . '/.codex/config.toml', 'home' => $home];
    }

    /**
     * Read, edit and write the config file, under a lock that is taken without blocking (a poll with a deadline).
     *
     * @param callable(string):array{text:string,changed:bool} $edit
     * @return array{ok:bool,changed?:bool,reason?:string}
     */
    private static function apply(callable $edit): array
    {
        $cfg = self::configFile();
        if (!$cfg['ok']) return $cfg;
        $file = $cfg['file'];
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['ok' => false, 'reason' => 'cannot_create_codex_folder'];
        $lockPath = $cfg['home'] . '/.aicli/codex-config.lock';
        @mkdir(dirname($lockPath), 0700, true);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) return ['ok' => false, 'reason' => 'cannot_lock'];
        try {
            $deadline = microtime(true) + 2.0;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) return ['ok' => false, 'reason' => 'lock_busy'];
                usleep(50000);
            }
            $old = is_file($file) ? (string)@file_get_contents($file) : '';
            $res = $edit($old);
            if (!$res['changed']) return ['ok' => true, 'changed' => false];
            $tmp = $file . '.aicli-tmp.' . getmypid();
            if (@file_put_contents($tmp, $res['text']) === false) { @unlink($tmp); return ['ok' => false, 'reason' => 'write_failed']; }
            @chmod($tmp, is_file($file) ? (fileperms($file) & 0777) : 0600);
            if (!@rename($tmp, $file)) { @unlink($tmp); return ['ok' => false, 'reason' => 'write_failed']; }
            return ['ok' => true, 'changed' => true];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Trust the worktree before the Codex helper starts.
     *
     * @param array<string,mixed> $wt the `worktree` block of the helper's `spawn` data
     * @return array{ok:bool,changed?:bool,reason?:string}
     */
    public static function trustWorktree(array $wt): array
    {
        $path = (string)($wt['path'] ?? '');
        if (!self::isToolMadeWorktree($wt, $path)) return ['ok' => false, 'reason' => 'not_a_tool_made_worktree'];
        $r = self::apply(static fn(string $t): array => self::addTrust($t, $path));
        if (empty($r['ok'])) LogService::log("Codex trust for '$path' failed: " . (string)($r['reason'] ?? 'unknown'), LogService::LOG_WARN, 'CodexTrustService');
        return $r;
    }

    /**
     * Remove the trust again when the helper closes.
     *
     * @param array<string,mixed> $wt
     * @return array{ok:bool,changed?:bool,reason?:string}
     */
    public static function untrustWorktree(array $wt): array
    {
        $path = (string)($wt['path'] ?? '');
        if (!self::isToolMadeWorktree($wt, $path)) return ['ok' => false, 'reason' => 'not_a_tool_made_worktree'];
        $r = self::apply(static fn(string $t): array => self::removeTrust($t, $path));
        if (empty($r['ok'])) LogService::log("Codex trust removal for '$path' failed: " . (string)($r['reason'] ?? 'unknown'), LogService::LOG_WARN, 'CodexTrustService');
        return $r;
    }
}
