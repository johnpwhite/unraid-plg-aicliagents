<?php

declare(strict_types=1);

namespace AICliAgents\Services;

/**
 * AUTO_CONTINUE_PATTERNS.md, part A: read one workspace's current screen as
 * plain, masked text.
 *
 * Used by the admin read tool `aicli_read_workspace_screen` (and its CLI verb
 * `read-screen`) and by the Settings "Test against a workspace screen" button.
 * Security rules (spec §Security):
 *   - the workspace id is validated before any lookup;
 *   - the text is plain (colour removed), capped in lines and in bytes;
 *   - known secret values and token-shaped strings are masked (mask());
 *   - every read writes ONE audit line to the plugin log (caller, target, size),
 *     never the screen text itself.
 */
final class WorkspaceScreenService
{
    public const DEFAULT_LINES = 40;
    public const MAX_LINES = 200;
    /** Byte cap for the returned text; the NEWEST lines are kept. */
    public const MAX_BYTES = 16384;
    /** Workspace ids are [A-Za-z0-9_-]; anything else is refused before a lookup. */
    private const ID_RE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Test seam: replaces the known-secret map (null = read the real vault files). */
    public static ?array $knownSecrets = null;

    public static function clampLines($raw): int
    {
        $n = is_numeric($raw) ? (int)$raw : self::DEFAULT_LINES;
        return max(1, min(self::MAX_LINES, $n));
    }

    /**
     * @return array<string,mixed> ['error'=>...] on refusal, else the screen record:
     *   workspaceId, name, agentId, agentVersion, model, provider, lines, text,
     *   lineCount, bytes, truncated
     */
    public static function read(string $workspaceId, $lines = null, string $caller = ''): array
    {
        $workspaceId = trim($workspaceId);
        if (!preg_match(self::ID_RE, $workspaceId)) {
            return ['error' => 'workspaceId must be a workspace id (letters, digits, - and _ only).'];
        }
        $n = self::clampLines($lines);
        $record = null;
        foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $workspaceId) { $record = $w; break; }
        }
        if ($record === null) return ['error' => "No workspace with id '$workspaceId' was found."];
        $name = (string)($record['name'] ?? $workspaceId);
        $agentId = (string)($record['agentId'] ?? '');
        if ($agentId === '') return ['error' => "Workspace '$name' has no agent on record."];

        $raw = TmuxService::capturePaneTail($agentId, $workspaceId, $n);
        if ($raw === null) {
            return ['error' => "Workspace '$name' has no terminal pane (it is not running)."];
        }

        $plain = TmuxService::plainPaneText($raw);
        $rows = preg_split('/\R/u', $plain) ?: [];
        $rows = array_map(static fn($r): string => rtrim((string)$r), $rows);
        while ($rows !== [] && end($rows) === '') array_pop($rows);
        $rows = array_slice($rows, -$n);
        $text = self::mask(implode("\n", $rows));

        $truncated = false;
        if (strlen($text) > self::MAX_BYTES) {
            // Keep the newest lines: cut at a line break inside the last MAX_BYTES.
            $tail = substr($text, -self::MAX_BYTES);
            $nl = strpos($tail, "\n");
            $text = $nl !== false ? substr($tail, $nl + 1) : $tail;
            if (function_exists('mb_scrub')) $text = mb_scrub($text, 'UTF-8');
            $truncated = true;
        }
        $outLines = $text === '' ? [] : explode("\n", $text);
        $seen = self::detectModel($outLines);
        $version = '';
        try { $version = AgentRegistry::getInstalledVersion($agentId); } catch (\Throwable $e) { $version = ''; }
        if ($version === '0.0.0') $version = '';

        self::audit($workspaceId, $agentId, $caller, count($outLines), strlen($text));

        return [
            'workspaceId' => $workspaceId,
            'name' => $name,
            'agentId' => $agentId,
            'agentVersion' => $version,
            'model' => $seen['model'],
            'provider' => $seen['provider'],
            'lineCount' => count($outLines),
            'bytes' => strlen($text),
            'truncated' => $truncated,
            'text' => $text,
        ];
    }

    /** The caller named in the audit line: the calling session, else the given fallback. */
    public static function callerLabel(string $fallback = 'unknown caller'): string
    {
        $sid = (string)(getenv('AICLI_SESSION_ID') ?: '');
        return $sid !== '' ? "session $sid" : $fallback;
    }

    /**
     * Spec §Security "Read-tool logging": one WARN line per read, so it survives
     * the "Warnings" log level. Never the screen text.
     */
    private static function audit(string $workspaceId, string $agentId, string $caller, int $lines, int $bytes): void
    {
        $who = $caller !== '' ? $caller : self::callerLabel();
        $who = (string)preg_replace('/[^A-Za-z0-9 _.:()\/-]/', '', $who);
        LogService::log("Screen read: workspace $workspaceId ($agentId) by $who — $lines lines, $bytes bytes", LogService::LOG_WARN, 'WorkspaceScreen');
    }

    /**
     * Mask secrets in screen text: known secret values first (verbatim), then
     * token-shaped strings (RedactionService::patternScrub) and a few extra
     * vendor prefixes. Text the agent already shows masked stays masked.
     */
    public static function mask(string $text): string
    {
        if ($text === '') return '';
        try {
            $known = self::$knownSecrets ?? RedactionService::loadKnownSecrets();
            $text = RedactionService::knownKeyScrub($text, $known);
        } catch (\Throwable $e) {
            // The vault could not be read: the pattern layer below still runs.
        }
        $text = RedactionService::patternScrub($text);
        $extra = [
            '/\bgithub_pat_[A-Za-z0-9_]{20,}/' => "\u{AB}redacted:gh\u{BB}",
            '/\bglpat-[A-Za-z0-9_-]{16,}/' => "\u{AB}redacted:gitlab\u{BB}",
            '/\bnvapi-[A-Za-z0-9_-]{16,}/' => "\u{AB}redacted:nvapi\u{BB}",
            '/\bxox[a-z]-[A-Za-z0-9-]{8,}/' => "\u{AB}redacted:slack\u{BB}",
            '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => "\u{AB}redacted:aws\u{BB}",
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}/' => "\u{AB}redacted:jwt\u{BB}",
            // An opaque 32+ character run with letters AND digits (an API key shape).
            '/\b(?=[A-Za-z0-9_-]{0,80}\d)(?=[A-Za-z0-9_-]{0,80}[A-Za-z])[A-Za-z0-9_-]{32,80}\b/' => "\u{AB}redacted:token\u{BB}",
        ];
        foreach ($extra as $re => $to) $text = (string)preg_replace($re, $to, $text);
        return $text;
    }

    /**
     * The active model / provider when the screen shows them, else null. Checks
     * the newest lines first. Shapes: "model: gpt-5-codex", "provider: openrouter",
     * OpenCode's footer "▣  Build · Nemotron 3 Ultra Free".
     *
     * @param array<int,string> $lines
     * @return array{model:?string,provider:?string}
     */
    public static function detectModel(array $lines): array
    {
        $model = null; $provider = null;
        foreach (array_reverse($lines) as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if ($model === null && preg_match('/\bmodel\s*[:=]\s*([\w.:\/@+-]{2,80})/iu', $line, $m)) $model = $m[1];
            if ($provider === null && preg_match('/\bprovider\s*[:=]\s*([\w.\/@+-]{2,60})/iu', $line, $m)) $provider = $m[1];
            if ($model === null && preg_match('/^[\s┃│]*▣\s+\S[^·]{0,40}·\s+(.{2,80}?)\s*$/u', $line, $m)) $model = trim($m[1]);
            if ($model !== null && $provider !== null) break;
        }
        if ($model === null) {
            foreach (array_reverse($lines) as $line) {
                if (preg_match('/\b((?:claude|gpt|gemini|qwen|kimi|grok|deepseek|llama|mistral|nemotron|codestral|glm)[-\w.:]{0,60})/iu', (string)$line, $m)) { $model = $m[1]; break; }
            }
        }
        return ['model' => $model, 'provider' => $provider];
    }
}
