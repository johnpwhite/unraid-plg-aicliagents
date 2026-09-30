<?php
/**
 * <module_context>
 *     <name>OpencodeClaudeInstructionsProjector</name>
 *     <description>Forgejo #311. OpenCode reads the user's ~/.claude/CLAUDE.md only as a
 *     FALLBACK: its global instruction list is [~/.config/opencode/AGENTS.md,
 *     ~/.claude/CLAUDE.md] and the FIRST file that exists wins (opencode
 *     packages/opencode/src/session/instruction.ts, `globalFiles` + the `break` in
 *     systemPaths(), tag v1.18.27; official docs https://opencode.ai/docs/rules/ —
 *     "Global-level fallback: ~/.claude/CLAUDE.md (used if no
 *     ~/.config/opencode/AGENTS.md exists)"). The Config Hub always writes
 *     ~/.config/opencode/AGENTS.md (Relay guidance + file-path policy), so from that
 *     moment OpenCode silently dropped the user's Claude global instructions. OpenCode
 *     never read ~/.claude/rules/ at all.
 *     This projector keeps them loaded through OpenCode's own `instructions` array in
 *     ~/.config/opencode/opencode.json. Each managed entry is ONE explicit file in the
 *     `~/`-relative form: OpenCode expands a leading `~/` to the home directory, and for
 *     an absolute path it globs only the BASENAME inside dirname (so a recursive
 *     `**` glob in an absolute path does not work) — explicit files are the reliable
 *     shape, and the directory walk (following symlinks) happens here in PHP. The
 *     entries are: ~/.claude/CLAUDE.md (when it exists) and every *.md file under
 *     ~/.claude/rules/ recursively, EXCEPT the plugin's own top-level aicli-*.md rule
 *     projections, whose content is already in AGENTS.md.</description>
 *     <dependencies>VendorProjector</dependencies>
 *     <constraints>Touches ONLY its own entries of the `instructions` array; user entries
 *     and every other key survive (stdClass round-trip, same encoding as
 *     JsonMcpProjector). An unparseable file, or an `instructions` value that is not a
 *     list, is never rewritten (write() returns false). Ownership is the hub ledger
 *     (managedKeys + lastProjectedHash) under its OWN ledger key, distinct from the
 *     MCP projector's ledger row for the same physical file.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class OpencodeClaudeInstructionsProjector extends VendorProjector {

    /** Managed-key prefix: 'instructions:<entry>' where <entry> is the exact array string. */
    const KEY_PREFIX = 'instructions:';

    /** OpenCode's switches that turn its Claude compatibility off (runtime-flags.ts). */
    const DISABLE_ENV = ['OPENCODE_DISABLE_CLAUDE_CODE', 'OPENCODE_DISABLE_CLAUDE_CODE_PROMPT'];

    /** The plugin's own Claude rule projections (content duplicated in AGENTS.md). */
    const OWN_RULE_PATTERN = '/^aicli-.*\.md$/';

    /** Directory-walk guards. */
    const MAX_DEPTH = 8;
    const MAX_FILES = 200;

    public function agentId(): string { return 'opencode'; }
    public function relPath(): string { return '.config/opencode/opencode.json'; }
    public function label(): string   { return 'OpenCode (Claude instructions)'; }

    /** Own ledger row: the MCP projector already owns '~/.config/opencode/opencode.json'. */
    public function ledgerKey(): string {
        return parent::ledgerKey() . '#claude-instructions';
    }

    /** The file whose existence turns OpenCode's CLAUDE.md fallback off. */
    public function agentsFileRel(): string { return '.config/opencode/AGENTS.md'; }

    /**
     * Desired entries. $input keys: 'entries' => string[] (already discovered).
     * Use desiredFor() in production; this keeps the VendorProjector contract.
     */
    public function desired(array $input): array {
        $out = [];
        foreach (($input['entries'] ?? []) as $entry) {
            $entry = (string)$entry;
            if ($entry === '') continue;
            $out[self::KEY_PREFIX . $entry] = $entry;
        }
        return $out;
    }

    /**
     * Desired managed entries for this home right now.
     * Empty when OpenCode's Claude compatibility is switched off in $env, or when
     * ~/.config/opencode/AGENTS.md does not exist (then OpenCode's own fallback still
     * loads ~/.claude/CLAUDE.md and nothing is lost).
     * @param array<string,string> $env
     */
    public function desiredFor(string $home, array $env): array {
        if (self::claudeCompatDisabled($env)) return [];
        $home = rtrim($home, '/');
        if (!is_file($home . '/' . $this->agentsFileRel())) return [];
        return $this->desired(['entries' => self::discoverEntries($home)]);
    }

    /**
     * True when either OpenCode switch is set to a true value. OpenCode parses them
     * with Effect's Config.boolean (runtime-flags.ts), which accepts
     * true/yes/on/1/y, case-insensitive.
     */
    public static function claudeCompatDisabled(array $env): bool {
        foreach (self::DISABLE_ENV as $k) {
            $v = strtolower(trim((string)($env[$k] ?? '')));
            if (in_array($v, ['1', 'true', 'yes', 'on', 'y'], true)) return true;
        }
        return false;
    }

    /**
     * '~/'-relative instruction entries: ~/.claude/CLAUDE.md first (when it is a
     * readable file, symlink allowed), then every *.md under ~/.claude/rules/
     * recursively (symlinked dirs followed, cycle-guarded), sorted, without the
     * plugin's own top-level aicli-*.md projections and without dot entries.
     * @return string[]
     */
    public static function discoverEntries(string $home): array {
        $home = rtrim($home, '/');
        $out = [];
        if (is_file($home . '/.claude/CLAUDE.md')) $out[] = '~/.claude/CLAUDE.md';
        $rules = [];
        $seen = [];
        self::walk($home . '/.claude/rules', '.claude/rules', 0, $seen, $rules);
        sort($rules, SORT_STRING);
        foreach ($rules as $rel) $out[] = '~/' . $rel;
        return $out;
    }

    private static function walk(string $abs, string $rel, int $depth, array &$seen, array &$out): void {
        if ($depth > self::MAX_DEPTH || count($out) >= self::MAX_FILES || !is_dir($abs)) return;
        $real = @realpath($abs);
        if ($real === false || isset($seen[$real])) return; // symlink loop guard
        $seen[$real] = true;
        $names = @scandir($abs);
        if (!is_array($names)) return;
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if ($name === '' || $name[0] === '.') continue;
            $childAbs = $abs . '/' . $name;
            $childRel = $rel . '/' . $name;
            if (is_dir($childAbs)) {
                self::walk($childAbs, $childRel, $depth + 1, $seen, $out);
                continue;
            }
            if (!is_file($childAbs) || !preg_match('/\.md$/i', $name)) continue;
            if ($depth === 0 && preg_match(self::OWN_RULE_PATTERN, $name)) continue;
            if (!is_readable($childAbs)) continue;
            $out[] = $childRel;
            if (count($out) >= self::MAX_FILES) return;
        }
    }

    public function current(string $file, array $keys): array {
        $data = self::decode($file);
        if ($data === null || !isset($data->instructions) || !is_array($data->instructions)) return [];
        $present = [];
        foreach ($data->instructions as $item) {
            if (is_string($item)) $present[$item] = true;
        }
        $out = [];
        foreach ($keys as $key) {
            $entry = self::entryFromKey($key);
            if ($entry !== null && isset($present[$entry])) $out[$key] = $entry;
        }
        return $out;
    }

    public function write(string $file, array $set, array $remove): bool {
        $data = self::decode($file);
        if ($data === null) {
            if (is_file($file) && trim((string)@file_get_contents($file)) !== '') {
                return false; // unparseable non-empty file — never clobber
            }
            $data = new \stdClass();
        }
        $had = isset($data->instructions);
        if ($had && !is_array($data->instructions)) return false; // user's non-list value — do not fight it
        $list = $had ? array_values($data->instructions) : [];

        $drop = [];
        foreach ($remove as $key) {
            $entry = self::entryFromKey((string)$key);
            if ($entry !== null) $drop[$entry] = true;
        }
        if ($drop) {
            $list = array_values(array_filter($list, fn($item) => !(is_string($item) && isset($drop[$item]))));
        }
        foreach ($set as $key => $value) {
            $entry = self::entryFromKey((string)$key);
            if ($entry === null) continue;
            if (!in_array($entry, $list, true)) $list[] = $entry;
        }

        if (empty($list) && !empty($drop)) {
            unset($data->instructions); // only our entries were there — leave no empty key behind
        } elseif (!empty($list) || $had) {
            $data->instructions = $list;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        return $this->atomicWrite($file, $json . "\n");
    }

    /** 'instructions:<entry>' → '<entry>' (null when not a managed-key shape). */
    public static function entryFromKey(string $key): ?string {
        if (strncmp($key, self::KEY_PREFIX, strlen(self::KEY_PREFIX)) !== 0) return null;
        $entry = substr($key, strlen(self::KEY_PREFIX));
        return $entry === '' ? null : $entry;
    }

    private static function decode(string $file): ?\stdClass {
        if (!is_file($file)) return null;
        $raw = (string)@file_get_contents($file);
        if (trim($raw) === '') return null;
        $data = json_decode($raw);
        return ($data instanceof \stdClass) ? $data : null;
    }
}
