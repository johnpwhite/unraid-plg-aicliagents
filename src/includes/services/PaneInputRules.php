<?php
/**
 * #157: effective rules for the terminal-input safety gate.
 *
 * The gate (TmuxService::paneStateFromCapture) decides whether it is safe to
 * paste + Enter into an agent's pane. This governs every caller of
 * TmuxService::paneAcceptsInput() — a Relay notice, the operator's Continue
 * nudge, and Reload — not only Relay (renamed from RelayGateRules 2026-09-09;
 * the old name fit only its first consumer). Rules come in two kinds:
 *   - BLOCK (shared by every agent): a screen shape where a stray Enter would
 *     confirm a decision — a menu cursor, (y/n), "proceed?", a pager, a
 *     dismissable "Esc to close" overlay.
 *   - IDLE (per agent): the agent's known idle prompt shape. When an agent has
 *     an idle profile, delivery requires the prompt to be RECOGNISED; anything
 *     else is held as `unknown-idle-shape` (the safety net for Forgejo #156).
 *     An agent without a profile keeps block-list-only behaviour.
 *
 * Effective rules = baked defaults (this file) overlaid with a user layer on
 * flash (pane-input-rules.json): `add` extra patterns, `disable` a baked id.
 * Every pattern, baked or user, passes ONE guarded loader: length cap, compile
 * check, nested-quantifier rejection, and a backtrack limit at match time, so a
 * bad user pattern can never brick delivery — it is dropped and logged and the
 * defaults stand. Spec: docs/specs/PANE_INPUT_HYBRID_ALLOWLIST.md
 *
 * MIGRATION (2026-09-09): the overlay file used to be named relay-gate-rules.json.
 * readOverlay() adopts an existing one of those, once, so an operator's tuned
 * rules are never silently lost by the rename — see readOverlay().
 */
namespace AICliAgents\Services;

class PaneInputRules {
    const SCHEMA = 1;
    const MAX_PATTERN_LEN = 512;
    const MAX_USER_PATTERNS = 32;
    const MAX_ID_LEN = 48;

    /** The user overlay on flash. Test seam via AICLI_PANE_INPUT_RULES. */
    public static function overlayPath(): string {
        $env = getenv('AICLI_PANE_INPUT_RULES');
        return ($env !== false && $env !== '') ? $env : '/boot/config/plugins/unraid-aicliagents/pane-input-rules.json';
    }

    /**
     * Pre-rename filename (was relay-gate-rules.json, same directory as
     * overlayPath()). Read-only migration source — see readOverlay().
     */
    private static function legacyOverlayPath(): string {
        return dirname(self::overlayPath()) . '/relay-gate-rules.json';
    }

    /**
     * Baked defaults. Ids are stable so a user can `disable` one by name and a
     * later plugin version that adds a pattern takes effect automatically.
     * @return array{block:array<int,array{id:string,re:string}>,idle:array<string,array<int,array{id:string,re:string}>>}
     */
    public static function defaults(): array {
        return [
            'block' => [
                // A menu cursor sits ON its option row — the arrow and the option are on
                // the SAME line. The inter-token whitespace is HORIZONTAL only
                // ([^\S\r\n]) so Claude's lone "❯" + nbsp prompt above its box border is
                // never read as a menu (that mistake once refused every idle Claude pane).
                // A plain '>' is included HERE but never in selector-cursor: requiring a digit
                // and a . or ) after it makes '> 1.' an unambiguous menu row, whereas a bare
                // '>' matches shell prompts and quoted text everywhere. antigravity-cli's
                // COMMAND-APPROVAL prompt renders exactly that way ('> 1. Yes, run command'),
                // captured on .4 2026-09-10; it was already caught by modal-overlay and
                // menu-nav-hint, but an approval prompt must not depend on an agent happening
                // to print 'esc to cancel'. A markdown-quoted numbered list may now defer —
                // a spurious defer is recoverable, a confirmed command is not.
                ['id' => 'option-selector', 're' => '/(?:^|[^\S\r\n])[❯➤▶»›>][^\S\r\n]*\d+[.)]/mu'],
                // Bare selection cursor on an option row (arrow + a choice after it, same line).
                ['id' => 'selector-cursor', 're' => '/^[^\S\r\n]*[❯➤▶»›][^\S\r\n]+\S/mu'],
                // A keyboard-navigation hint means a menu is OPEN, whatever glyph marks the
                // selected row. antigravity-cli renders its trust prompt as "> Yes, I trust
                // this folder / No, exit" with a plain '>' that no cursor pattern matches —
                // the gate said ready, so a paste plus Enter would have CONFIRMED a
                // permission prompt the operator never saw. Match the hint instead of the
                // cursor: it is specific, and it generalises to any TUI that draws one.
                ['id' => 'menu-nav-hint',   're' => '/[\x{2191}\x{2193}].{0,24}\b(?:navigate|choose|select|move)\b|\benter\s+(?:to\s+)?(?:confirm|select|choose|accept)\b/iu'],
                // The plugin's OWN parked-pane banner (aicli-shell.sh). An agent that exited
                // leaves "Press ENTER to retry" on screen; the liveness probe still sees the
                // run-loop shell as alive, so the gate said ready and a paste's Enter would
                // have fired the retry. kilocode and grok-build both sat in this state.
                // An agent waiting on a browser/device-code sign-in must never be typed
                // into. grok-build parks on "Approve in your browser to finish signing in"
                // with a device code and "Waiting for approval..." — captured on .4
                // 2026-09-10, where the gate said deliver. There is no input box on that
                // screen, so a paste is noise and its Enter is a keypress nobody intended.
                ['id' => 'auth-pending',   're' => '/Waiting for approval|Approve in your browser|to finish signing in|Make sure your browser shows this code/i'],
                ['id' => 'agent-exited',    're' => '/\[Agent Exited[^\]]*\]|Press ENTER to \\w+/i'],
                ['id' => 'yes-no',          're' => '/\[(?:y\/n|yes\/no)\]|\((?:y\/n|yes\/no)\)/i'],
                ['id' => 'do-you-want',     're' => '/\bdo you want to\b/i'],
                ['id' => 'proceed',         're' => '/\bproceed\?/i'],
                ['id' => 'pager',           're' => '/\(END\)|--More--/'],
                // A dismissable full-screen overlay — settings/config/menu/help — shows an
                // "… Esc to close/cancel/exit" footer. A paste+Enter lands IN the overlay
                // (2026-08-22: a Relay notice typed into a Config settings search box).
                // Only close/cancel/exit/dismiss/quit — NOT the busy-state "esc to interrupt".
                ['id' => 'modal-overlay',   're' => '/\besc(?:ape)?\s+to\s+(?:close|cancel|exit|dismiss|go\s+back|quit)\b/i'],
            ],
            'idle' => [
                // Claude Code's idle input prompt: a lone "❯" (+ nbsp) on its own line
                // inside the input box. A menu row ("❯ 1. Yes") has text after the arrow
                // and is caught by the block list first. Captured on .4, 2026-08.
                'claude-code' => [
                    ['id' => 'claude-idle-prompt', 're' => '/^[^\S\r\n]*❯[ \t\x{00A0}]*$/mu'],
                ],
                // codex-cli: a bare '›'. Captured idle on .4 2026-09-10 as
                // "\e[1m›\e[0m \e[2mAsk Codex to do anything\e[0m" — the placeholder is dim,
                // so the ghost-suggestion reducer collapses it to the bare prompt first.
                // antigravity-cli: a bare '>' inside a rule-bordered box, footer
                // "? for shortcuts". Captured in its IN-USE state on .4 2026-09-10 —
                // its first screen is a trust prompt, which is a DIFFERENT shape and is
                // caught by the menu-nav-hint block rule instead. '>' is far too common
                // to add to the shared cursor patterns, so it is matched here only, as a
                // whole line. Typed input makes the line "> text", which no longer
                // matches, and a profiled agent with no idle match DEFERS.
                'antigravity-cli' => [
                    ['id' => 'antigravity-idle-prompt', 're' => '/^[^\S\r\n]*>[ \t\x{00A0}]*$/mu'],
                ],
                // grok-build: same boxed shape as kimi-code but with '❯'. Captured on .4
                // 2026-09-10 once the operator completed its device-code sign-in. Note the
                // shared cursor patterns do NOT catch its typed state, because the line
                // starts with the box border rather than the glyph — the profile is what
                // makes typed input defer, via the unknown-idle-shape rule.
                'grok-build' => [
                    ['id' => 'grok-idle-prompt', 're' => '/^[^\S\r\n]*│[^\S\r\n]*❯[ \t\x{00A0}]*│?[ \t]*$/mu'],
                ],
                // kimi-code: a '>' prompt inside a rounded box — the idle line is
                // "│ >" with nothing after it. Captured on .4 2026-09-10 from a real
                // session, after its trust prompt and self-update had been dealt with.
                // Typed input makes it "│ > text", which stops matching, and a profiled
                // agent with no idle match DEFERS. Verified both ways on the live pane.
                'kimi-code' => [
                    ['id' => 'kimi-idle-prompt', 're' => '/^[^\S\r\n]*│[^\S\r\n]*>[ \t\x{00A0}]*│?[ \t]*$/mu'],
                ],
                'codex-cli' => [
                    ['id' => 'codex-idle-prompt', 're' => '/^[^\S\r\n]*›[ \t\x{00A0}]*$/mu'],
                ],
            ],
        ];
    }

    /** Why a pattern is unusable, or null when it is safe to compile and run. */
    public static function patternError($re): ?string {
        if (!is_string($re) || $re === '') return 'pattern must be a non-empty string';
        if (strlen($re) > self::MAX_PATTERN_LEN) return 'pattern longer than ' . self::MAX_PATTERN_LEN . ' bytes';
        if (!preg_match('#^/.*/[imsuxU]*$#s', $re)) return 'pattern must be written as /body/flags (allowed flags: i m s u x U)';
        // Nested quantifiers ((a+)+, (\w*)*, (x+){2,}) are the classic catastrophic
        // backtracking shape; refuse them outright rather than rely on the limit.
        if (preg_match('/\([^()]*[+*}]\)\s*[+*{]/', $re)) return 'nested quantifier (catastrophic backtracking risk)';
        if (@preg_match($re, '') === false) return 'does not compile: ' . preg_last_error_msg();
        return null;
    }

    /** Match under a hard backtrack budget; a limit hit counts as NO match, never as an error. */
    public static function safeMatch(string $re, string $subject): bool {
        $prevB = ini_get('pcre.backtrack_limit'); $prevR = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', '20000'); ini_set('pcre.recursion_limit', '2000');
        try {
            $r = @preg_match($re, $subject);
            return $r === 1;
        } finally {
            if ($prevB !== false) ini_set('pcre.backtrack_limit', (string)$prevB);
            if ($prevR !== false) ini_set('pcre.recursion_limit', (string)$prevR);
        }
    }

    /**
     * The raw user layer, or [] when absent/corrupt (corruption is logged, never fatal).
     *
     * MIGRATION: when the new-named overlay is absent but the pre-rename
     * relay-gate-rules.json still exists (a Factory tester's tuned rules from
     * before the 2026-09-09 rename), adopt it — read it, and persist it forward
     * under the new filename so this is a one-time read-through, not a repeated
     * fallback. The legacy file is left in place untouched. If the write-forward
     * fails, the read content is still returned so nothing is lost for the
     * current request; the adoption is simply retried on the next read.
     */
    public static function readOverlay(): array {
        $file = self::overlayPath();
        if (!is_file($file)) {
            $legacy = self::legacyOverlayPath();
            if (!is_file($legacy)) return [];
            $raw = json_decode((string)@file_get_contents($legacy), true);
            if (!is_array($raw)) {
                LogService::log('legacy relay-gate-rules.json is not valid JSON — using baked defaults', LogService::LOG_WARN, 'PaneInputRules');
                return [];
            }
            if (AtomicWriteService::writeJson($file, $raw)) {
                LogService::log('Adopted relay-gate-rules.json as pane-input-rules.json (2026-09-09 rename)', LogService::LOG_INFO, 'PaneInputRules');
            }
            return $raw;
        }
        $raw = json_decode((string)@file_get_contents($file), true);
        if (!is_array($raw)) {
            LogService::log('pane-input-rules.json is not valid JSON — using baked defaults', LogService::LOG_WARN, 'PaneInputRules');
            return [];
        }
        return $raw;
    }

    /**
     * Normalise + validate a user layer. Unusable patterns are reported in
     * `errors` and dropped from `clean`; everything else is kept, so one bad
     * pattern never discards the rest of the layer.
     * @return array{clean:array,errors:string[]}
     */
    public static function validateOverlay(array $layer): array {
        $errors = []; $clean = ['version' => self::SCHEMA, 'block' => ['add' => [], 'disable' => []], 'idle' => []];
        $count = 0;
        $section = static function ($sec, string $where) use (&$errors, &$count): array {
            $out = ['add' => [], 'disable' => []];
            if (!is_array($sec)) return $out;
            foreach ((array)($sec['disable'] ?? []) as $id) {
                if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,47}$/', $id)) $out['disable'][] = $id;
                else $errors[] = "$where: ignored invalid disable id";
            }
            foreach ((array)($sec['add'] ?? []) as $i => $p) {
                $id = is_array($p) ? (string)($p['id'] ?? '') : '';
                $re = is_array($p) ? ($p['re'] ?? null) : null;
                if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,47}$/', $id)) $id = 'user-' . substr(md5((string)json_encode($p)), 0, 8);
                if (++$count > self::MAX_USER_PATTERNS) { $errors[] = "$where: too many user patterns (max " . self::MAX_USER_PATTERNS . ")"; break; }
                $err = self::patternError($re);
                if ($err !== null) { $errors[] = "$where: pattern '$id' dropped — $err"; continue; }
                $out['add'][] = ['id' => $id, 're' => (string)$re];
            }
            return $out;
        };
        $clean['block'] = $section($layer['block'] ?? [], 'block');
        foreach ((array)($layer['idle'] ?? []) as $agentId => $sec) {
            if (!is_string($agentId) || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $agentId)) { $errors[] = 'idle: ignored invalid agent id'; continue; }
            $clean['idle'][$agentId] = $section($sec, "idle/$agentId");
        }
        return ['clean' => $clean, 'errors' => $errors];
    }

    /** Validate then persist the user layer atomically. Returns status + any dropped-pattern errors. */
    public static function saveOverlay(array $layer): array {
        $v = self::validateOverlay($layer);
        if (!AtomicWriteService::writeJson(self::overlayPath(), $v['clean'])) {
            return ['status' => 'error', 'message' => 'Could not write pane-input-rules.json'];
        }
        LogService::log('Pane input rules saved (' . count($v['errors']) . ' pattern(s) rejected)', LogService::LOG_INFO, 'PaneInputRules');
        return ['status' => 'ok', 'errors' => $v['errors'], 'rules' => self::effective($v['clean'])];
    }

    /**
     * Everything the UI needs: each rule with its source (baked|user) and
     * whether it is disabled, plus loader errors. Disabled baked rules stay
     * listed so the user can re-enable them.
     */
    public static function effective(?array $overlay = null): array {
        $v = self::validateOverlay($overlay ?? self::readOverlay());
        $layer = $v['clean']; $errors = $v['errors']; $d = self::defaults();
        $merge = static function (array $baked, array $sec) use (&$errors): array {
            $disabled = array_fill_keys($sec['disable'] ?? [], true);
            $out = [];
            foreach ($baked as $p) {
                $err = self::patternError($p['re']);
                if ($err !== null) { $errors[] = "baked pattern '{$p['id']}' dropped — $err"; continue; }
                $out[] = ['id' => $p['id'], 're' => $p['re'], 'source' => 'baked', 'disabled' => isset($disabled[$p['id']])];
            }
            foreach ($sec['add'] ?? [] as $p) $out[] = ['id' => $p['id'], 're' => $p['re'], 'source' => 'user', 'disabled' => isset($disabled[$p['id']])];
            return $out;
        };
        $idle = [];
        foreach (array_unique(array_merge(array_keys($d['idle']), array_keys($layer['idle']))) as $agentId) {
            $idle[$agentId] = $merge($d['idle'][$agentId] ?? [], $layer['idle'][$agentId] ?? []);
        }
        return ['block' => $merge($d['block'], $layer['block']), 'idle' => $idle, 'errors' => $errors];
    }

    /**
     * The rule set the classifier runs: enabled, compilable patterns only.
     * @return array{block:array<string,string>,idle:array<string,array<string,string>>}
     */
    public static function compiled(?array $overlay = null): array {
        $e = self::effective($overlay);
        $pick = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) if (empty($r['disabled'])) $out[$r['id']] = $r['re'];
            return $out;
        };
        $idle = [];
        foreach ($e['idle'] as $agentId => $rows) { $p = $pick($rows); if ($p !== []) $idle[$agentId] = $p; }
        return ['block' => $pick($e['block']), 'idle' => $idle];
    }
}
