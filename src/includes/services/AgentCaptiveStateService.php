<?php
/**
 * <module_context>
 *     <name>AgentCaptiveStateService</name>
 *     <description>Keeps the user state that a curl_install vendor installer writes
 *     inside the agent's own tree (the "captive home", for example
 *     agents/kimi-code/home/.kimi-code/region) in ONE version-independent store,
 *     /boot/config/plugins/unraid-aicliagents/agent-state/&lt;id&gt;/. After a
 *     successful install each agent
 *     generation holds a symlink to the store instead of its own copy, so two
 *     generations that are mounted side by side read and write the same file.
 *     docs/specs/SIDE_BY_SIDE_AGENT_INSTALLS.md "Phase 4 — 2026-09-23" (Forgejo #270).</description>
 *     <dependencies>StoragePathResolver (PLUGIN_BASE), LogService, LifecycleLogService (optional)</dependencies>
 *     <constraints>Never deletes the only copy of a state file. Migration copies,
 *     it never moves: the old generation keeps its file as the rollback copy.
 *     A store entry that exists is never overwritten by migration (exactly once).
 *     A replaced store entry is kept under .rollback/. Every copy goes through a
 *     temporary name and one rename. Paths are validated and a symlink inside the
 *     store is refused, so a planted link cannot redirect a write.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/StoragePathResolver.php';

final class AgentCaptiveStateService
{
    /** Directory name of the store, under the plugin's flash config folder. */
    public const STORE_DIR = 'agent-state';

    /** Suffix of an in-flight copy. A leftover one is an interrupted copy. */
    public const TMP_SUFFIX = '.aicli-tmp';

    /** Rollback copies kept per agent (newest first). */
    public const ROLLBACK_KEEP = 3;

    /**
     * The version-independent store root. AICLI_AGENT_STATE_ROOT is a TEST-ONLY
     * override (the same shape as AICLI_STORAGECTL); production never sets it.
     * The path does not depend on the storage target setting on purpose: a
     * generation layer holds an absolute symlink to it, and a storage move must
     * not break that link.
     */
    public static function storeRoot(): string
    {
        $env = getenv('AICLI_AGENT_STATE_ROOT');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        return StoragePathResolver::PLUGIN_BASE . '/' . self::STORE_DIR;
    }

    /** The store directory of one agent. */
    public static function agentStore(string $agentId): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $agentId)) {
            throw new \InvalidArgumentException("AgentCaptiveStateService: invalid agent id '$agentId'");
        }
        return self::storeRoot() . '/' . $agentId;
    }

    /**
     * A declared state path is relative to the agent directory, has no empty,
     * "." or ".." segment, and uses only file-name characters. Anything else is
     * refused, because the value becomes part of a path we write to.
     */
    public static function isValidRelPath(string $rel): bool
    {
        if ($rel === '' || $rel[0] === '/' || strlen($rel) > 255) {
            return false;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
            if (!preg_match('/^[A-Za-z0-9._@+-]+$/', $seg)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The verified list of captive state paths for an agent, or null when the
     * registry makes no valid declaration. Null is the fail-safe answer: the
     * caller must then treat the agent as NOT safe for side-by-side upgrades.
     * An empty list is a real answer: "the installer writes no user state".
     *
     * @return list<string>|null
     */
    public static function declaredPaths(array $agent): ?array
    {
        $src = $agent['source'] ?? null;
        if (!is_array($src) || !array_key_exists('captive_state', $src)) {
            return null;
        }
        $list = $src['captive_state'];
        if (!is_array($list)) {
            return null;
        }
        $out = [];
        foreach ($list as $rel) {
            if (!is_string($rel) || !self::isValidRelPath($rel)) {
                return null;
            }
            $out[] = $rel;
        }
        return array_values(array_unique($out));
    }

    /**
     * Step 1, before the installer wipes the captive home: copy each declared
     * state path that the current tree holds as a REAL file or directory into
     * the store, when the store does not have it yet. This is the one-time
     * migration of an existing install.
     *
     * Safety: the source is never changed or removed. The old generation keeps
     * its copy, which is the rollback copy until that generation is released.
     * A store entry that exists is never overwritten, so a second run (or a
     * run against an older generation) changes nothing. An interrupted copy
     * leaves only a *.aicli-tmp entry, which the next run removes.
     *
     * @param list<string> $paths
     * @return array<string,string> rel => outcome
     */
    public static function migrate(string $agentId, string $agentDir, array $paths): array
    {
        $store = self::agentStore($agentId);
        $report = [];
        foreach ($paths as $rel) {
            if (!self::isValidRelPath($rel)) { $report[$rel] = 'invalid'; continue; }
            $src = rtrim($agentDir, '/') . '/' . $rel;
            $dst = $store . '/' . $rel;
            if (!self::storePathIsSafe($store, $rel)) { $report[$rel] = 'refused_link_in_store'; continue; }
            self::removeTree($dst . self::TMP_SUFFIX);
            if (is_link($src)) { $report[$rel] = 'already_linked'; continue; }
            if (!file_exists($src)) { $report[$rel] = 'absent'; continue; }
            if (file_exists($dst) || is_link($dst)) { $report[$rel] = 'store_has'; continue; }
            if (!self::copyInto($src, $dst)) { $report[$rel] = 'copy_failed'; continue; }
            $report[$rel] = 'migrated';
        }
        self::record($agentId, 'agent_captive_state_migrate', $report);
        return $report;
    }

    /**
     * Step 2, after the wipe and before the vendor script runs: put a COPY of
     * each declared path that the store holds into the captive tree, so the
     * installer sees the shared state (for example kimi's "never overwrite an
     * existing region marker" rule) instead of an empty captive home.
     *
     * A copy, not a link: the installer never writes the store directly. A
     * script that fails half way therefore cannot change the shared state;
     * only settle() does that, and only after the script succeeded.
     *
     * @param list<string> $paths
     * @return array<string,string>
     */
    public static function seed(string $agentId, string $agentDir, array $paths): array
    {
        $store = self::agentStore($agentId);
        $report = [];
        foreach ($paths as $rel) {
            if (!self::isValidRelPath($rel)) { $report[$rel] = 'invalid'; continue; }
            if (!self::storePathIsSafe($store, $rel)) { $report[$rel] = 'refused_link_in_store'; continue; }
            $dst = $store . '/' . $rel;
            if (!file_exists($dst)) { $report[$rel] = 'not_in_store'; continue; }
            $target = rtrim($agentDir, '/') . '/' . $rel;
            if (is_link($target)) @unlink($target);
            if (file_exists($target)) { $report[$rel] = 'real_entry_kept'; continue; }
            $report[$rel] = self::copyInto($dst, $target) ? 'seeded' : 'seed_failed';
        }
        return $report;
    }

    /**
     * Step 3, after the vendor script succeeded: make the store the owner of
     * every declared path again.
     *
     *  - still our link            -> nothing to do;
     *  - missing, store has it     -> the link is put back;
     *  - a real entry, store empty -> adopted into the store, then linked;
     *  - a real entry, same bytes  -> linked;
     *  - a real entry that differs -> the installer changed the seeded copy on
     *    purpose (e.g. grok merges its [cli] block into config.toml), so it
     *    becomes the store content. The previous store content is copied to
     *    .rollback/<time>/ first.
     *
     * @param list<string> $paths
     * @return array<string,string>
     */
    public static function settle(string $agentId, string $agentDir, array $paths): array
    {
        $store = self::agentStore($agentId);
        $rollback = null;
        $report = [];
        foreach ($paths as $rel) {
            if (!self::isValidRelPath($rel)) { $report[$rel] = 'invalid'; continue; }
            if (!self::storePathIsSafe($store, $rel)) { $report[$rel] = 'refused_link_in_store'; continue; }
            $target = rtrim($agentDir, '/') . '/' . $rel;
            $dst = $store . '/' . $rel;
            if (is_link($target)) {
                $report[$rel] = (readlink($target) === $dst) ? 'linked' : 'foreign_link_kept';
                continue;
            }
            if (!file_exists($target)) {
                if (file_exists($dst)) {
                    $report[$rel] = self::placeLink($target, $dst) ? 'relinked' : 'link_failed';
                } else {
                    $report[$rel] = 'absent';
                }
                continue;
            }
            // A real entry the installer produced.
            if (!file_exists($dst)) {
                if (!self::copyInto($target, $dst)) { $report[$rel] = 'copy_failed'; continue; }
                $report[$rel] = self::replaceWithLink($target, $dst) ? 'adopted' : 'adopted_unlinked';
                continue;
            }
            if (self::sameContent($target, $dst)) {
                $report[$rel] = self::replaceWithLink($target, $dst) ? 'linked' : 'link_failed';
                continue;
            }
            $rollback ??= $store . '/.rollback/' . gmdate('Ymd\THis\Z') . '-' . getmypid();
            if (!self::copyInto($dst, $rollback . '/' . $rel)) { $report[$rel] = 'rollback_failed'; continue; }
            if (!self::copyInto($target, $dst, true)) { $report[$rel] = 'copy_failed'; continue; }
            $report[$rel] = self::replaceWithLink($target, $dst) ? 'replaced' : 'replaced_unlinked';
        }
        if ($rollback !== null) {
            self::pruneRollbacks($store);
        }
        self::record($agentId, 'agent_captive_state_settle', $report);
        return $report;
    }

    // ---------------------------------------------------------------------
    // Filesystem helpers. Each one refuses to follow a symlink it did not make.
    // ---------------------------------------------------------------------

    /** No component of $rel inside the store may be a symlink. */
    private static function storePathIsSafe(string $store, string $rel): bool
    {
        if (is_link($store)) return false;
        $p = $store;
        foreach (explode('/', $rel) as $seg) {
            $p .= '/' . $seg;
            if (is_link($p)) return false;
        }
        return true;
    }

    /**
     * Copy $src (file or tree) to $dst through $dst.aicli-tmp and one rename.
     * With $replace, an existing $dst is swapped out: a file is replaced by the
     * rename itself; a directory is renamed aside first and removed after.
     */
    private static function copyInto(string $src, string $dst, bool $replace = false): bool
    {
        $parent = dirname($dst);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true)) return false;
        $tmp = $dst . self::TMP_SUFFIX;
        self::removeTree($tmp);
        if (!self::copyTree($src, $tmp)) { self::removeTree($tmp); return false; }
        if (file_exists($dst) || is_link($dst)) {
            if (!$replace) { self::removeTree($tmp); return false; }
            if (is_dir($dst) && !is_link($dst)) {
                $aside = $dst . '.aicli-old';
                self::removeTree($aside);
                if (!@rename($dst, $aside)) { self::removeTree($tmp); return false; }
                if (!@rename($tmp, $dst)) { @rename($aside, $dst); self::removeTree($tmp); return false; }
                self::removeTree($aside);
                return true;
            }
        }
        if (!@rename($tmp, $dst)) { self::removeTree($tmp); return false; }
        return true;
    }

    /** Recursive copy that keeps modes and copies symlinks as symlinks. */
    private static function copyTree(string $src, string $dst): bool
    {
        if (is_link($src)) {
            return @symlink((string)readlink($src), $dst);
        }
        if (is_file($src)) {
            if (!@copy($src, $dst)) return false;
            @chmod($dst, fileperms($src) & 0777);
            return true;
        }
        if (!is_dir($src)) return false;
        if (!@mkdir($dst, fileperms($src) & 0777 ?: 0700)) return false;
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!self::copyTree("$src/$name", "$dst/$name")) return false;
        }
        @chmod($dst, fileperms($src) & 0777);
        return true;
    }

    /** Remove a file, a symlink (never its target) or a tree. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            self::removeTree("$path/$name");
        }
        @rmdir($path);
    }

    private static function sameContent(string $a, string $b): bool
    {
        if (is_file($a) && is_file($b)) {
            return filesize($a) === filesize($b) && hash_file('sha256', $a) === hash_file('sha256', $b);
        }
        if (is_dir($a) && is_dir($b)) {
            $la = array_values(array_diff(scandir($a) ?: [], ['.', '..']));
            $lb = array_values(array_diff(scandir($b) ?: [], ['.', '..']));
            if ($la !== $lb) return false;
            foreach ($la as $n) {
                if (is_link("$a/$n") || is_link("$b/$n")) {
                    if (!is_link("$a/$n") || !is_link("$b/$n") || readlink("$a/$n") !== readlink("$b/$n")) return false;
                    continue;
                }
                if (!self::sameContent("$a/$n", "$b/$n")) return false;
            }
            return true;
        }
        return false;
    }

    /** Create the link at $target (parent made as needed). */
    private static function placeLink(string $target, string $dst): bool
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) return false;
        if (is_link($target)) @unlink($target);
        return @symlink($dst, $target);
    }

    /**
     * Swap a real entry for a link to the store. The link is made under a
     * temporary name first; the real entry is removed only after the link is
     * in place, so a failure leaves a working (unshared) copy, never nothing.
     */
    private static function replaceWithLink(string $target, string $dst): bool
    {
        $tmpLink = $target . '.aicli-link';
        @unlink($tmpLink);
        if (!@symlink($dst, $tmpLink)) return false;
        if (is_dir($target) && !is_link($target)) {
            $aside = $target . '.aicli-old';
            self::removeTree($aside);
            if (!@rename($target, $aside)) { @unlink($tmpLink); return false; }
            if (!@rename($tmpLink, $target)) { @rename($aside, $target); @unlink($tmpLink); return false; }
            self::removeTree($aside);
            return true;
        }
        if (!@rename($tmpLink, $target)) { @unlink($tmpLink); return false; }
        return true;
    }

    private static function pruneRollbacks(string $store): void
    {
        $dirs = glob($store . '/.rollback/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs, SORT_STRING);
        foreach (array_slice($dirs, self::ROLLBACK_KEEP) as $old) {
            self::removeTree($old);
        }
    }

    private static function record(string $agentId, string $event, array $report): void
    {
        $changed = array_filter($report, static fn($o) => !in_array($o, ['absent', 'linked', 'already_linked', 'store_has', 'not_in_store'], true));
        if ($changed === []) return;
        if (class_exists('\AICliAgents\Services\LogService')) {
            LogService::log("$event $agentId: " . json_encode($report), LogService::LOG_INFO, 'AgentCaptiveStateService');
        }
        if (class_exists('\AICliAgents\Services\LifecycleLogService')) {
            try {
                LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'installer', $event, ['agent' => $agentId, 'paths' => $report]);
            } catch (\Throwable $e) {
                // Logging must never break an install.
            }
        }
    }
}
