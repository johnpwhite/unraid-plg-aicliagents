<?php
/**
 * <module_context>
 *     <name>GeminiCommandsProjector</name>
 *     <description>Gemini CLI custom-commands projector (config-surface audit 2026-06).
 *     Gemini reads custom commands from ~/.gemini/commands/&lt;name&gt;.toml in TOML — NOT
 *     the flat Markdown the hub stores and every other commands agent reads (verified
 *     against the official docs at geminicli.com/docs/cli/custom-commands: "Your command
 *     definition files MUST be written in the TOML format" with a required `prompt`
 *     string and optional `description`). So this TreeProjector subclass transpiles each
 *     canonical '&lt;name&gt;.md' into '&lt;name&gt;.toml' — the markdown body becomes the TOML
 *     `prompt`, and a leading YAML-frontmatter `description:` (if any) becomes the TOML
 *     `description`. Everything else (managed-key discipline, three-way drift, never-
 *     clobber) is inherited unchanged; the transformed '.toml' relPaths are the managed
 *     keys, so current()/write()/drift all act on the real on-disk files.</description>
 *     <dependencies>TreeProjector, Transpiler (tomlString escaper)</dependencies>
 *     <constraints>Deterministic output (stable projection hash). tomlString emits a
 *     single-line basic string with \n escapes — valid TOML, byte-stable. Never logs
 *     command content.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class GeminiCommandsProjector extends TreeProjector {

    /**
     * Remap the canonical '<name>.md'=>markdown map to Gemini's '<name>.toml'=>TOML.
     * @param array<string,string> $files
     * @return array<string,string>
     */
    protected function transformFiles(array $files): array {
        $out = [];
        foreach ($files as $rel => $content) {
            $rel = (string)$rel;
            $base = preg_match('/\.md$/i', $rel) ? substr($rel, 0, -3) : $rel;
            $key  = ($base === $rel) ? $rel : $base . '.toml';
            $out[$key] = self::toToml((string)$content);
        }
        return $out;
    }

    /** A markdown command body → a Gemini TOML command (prompt + optional description). */
    private static function toToml(string $md): string {
        list($desc, $body) = self::splitFrontmatter($md);
        $lines = [];
        if ($desc !== null && $desc !== '') {
            $lines[] = 'description = ' . Transpiler::tomlString($desc);
        }
        $lines[] = 'prompt = ' . Transpiler::tomlString($body);
        return implode("\n", $lines) . "\n";
    }

    /**
     * Split optional leading YAML frontmatter. Returns [description|null, body].
     * Only a `description:` key is lifted (Gemini's one optional field); every other
     * frontmatter key is dropped (no Gemini equivalent). No / unterminated
     * frontmatter → [null, wholeContent].
     * @return array{0:?string,1:string}
     */
    private static function splitFrontmatter(string $md): array {
        if (!preg_match('/^---[ \t]*\r?\n/', $md)) return [null, $md];
        $rest = (string)preg_replace('/^---[ \t]*\r?\n/', '', $md, 1);
        if (!preg_match('/\r?\n---[ \t]*(\r?\n|$)/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            return [null, $md]; // unterminated frontmatter → treat the whole thing as body
        }
        $fmText = substr($rest, 0, $m[0][1]);
        $body   = substr($rest, $m[0][1] + strlen($m[0][0]));
        $desc = null;
        foreach (preg_split('/\r?\n/', $fmText) as $line) {
            if (preg_match('/^\s*description\s*:\s*(.*)$/i', $line, $mm)) {
                $desc = trim($mm[1]);
                $len = strlen($desc);
                if ($len >= 2 && ($desc[0] === '"' || $desc[0] === "'") && substr($desc, -1) === $desc[0]) {
                    $desc = substr($desc, 1, -1);
                }
                break;
            }
        }
        return [$desc, $body];
    }
}
