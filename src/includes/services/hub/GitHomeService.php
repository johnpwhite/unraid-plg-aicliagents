<?php
/**
 * <module_context>
 *     <name>GitHomeService</name>
 *     <description>Git-backed home config (OP #1365 / finding H-04, opt-in). Wraps a
 *     git repo rooted at the MOUNTED overlay agent home (same home_unavailable gate
 *     as HubProjector::resolveHome — never touches an unmounted home). init() writes
 *     the deny-by-default .gitignore (first rule `*`; explicit whitelist of every
 *     hub-projected file; the OAuth trio ~/.claude.json, ~/.gemini/mcp-oauth-tokens.json,
 *     ~/.codex/auth.json explicitly re-denied) then creates the repo + initial commit.
 *     Auto-commits are debounced: commitIfEnabled() appends the summary to a /tmp
 *     pending marker and only commits when the last commit is older than 30 s; a
 *     pending marker is flushed by the NEXT commitIfEnabled() or status() call (the
 *     UI polls status, so pending changes land within one poll cycle — no daemon
 *     change needed). Remote push/pull authenticate via a PAT from the SecretService
 *     vault (key HUB_GIT_TOKEN) injected through GIT_ASKPASS env — never argv, never
 *     disk, never logs.</description>
 *     <dependencies>HubProjector (resolveHome), ConfigService, SecretService, LogService</dependencies>
 *     <constraints>ALL git invocations via proc_open array form (no shell), as
 *     `git -C $home -c safe.directory=$home` (overlay mounts trip git's dubious-
 *     ownership check) with a fixed author identity (AICli Hub &lt;aicli@localhost&gt;)
 *     so the user's global gitconfig is never required nor modified. commit/restore
 *     take the SAME per-user lock as TaskService::persistHome
 *     (/tmp/unraid-aicliagents/init_&lt;user&gt;.lock) so git never interleaves with a
 *     bake. Never logs commit message bodies beyond names/counts, never logs the
 *     PAT. Test hooks: AICLI_HUB_HOME (via HubProjector), AICLI_HUB_GIT_TEST_ENABLED
 *     (force enabled+autocommit without touching /boot config),
 *     AICLI_HUB_GIT_DEBOUNCE_S (override the 30 s debounce).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

use AICliAgents\Services\ConfigService;
use AICliAgents\Services\LogService;
use AICliAgents\Services\SecretService;

class GitHomeService {

    /** Auto-commit debounce window (seconds). Env-overridable for tests. */
    const DEBOUNCE_S = 30;
    /** Fixed branch — keeps push/pull refspecs deterministic. */
    const BRANCH = 'main';
    /** Vault key holding the remote PAT (SecretService agent vault). */
    const TOKEN_KEY = 'HUB_GIT_TOKEN';
    /** .gitignore managed-block fence (user rules outside are preserved). */
    const GI_FENCE_OPEN  = '# >>> aicli-hub managed — do not edit inside >>>';
    const GI_FENCE_CLOSE = '# <<< aicli-hub <<<';
    /** Clean/smudge filter name (sanitizes resolved secrets <-> {KEY} placeholders). */
    const SECRET_FILTER = 'aicli-secrets';
    /** Plugin config dir on flash (overridable for tests via AICLI_PLUGIN_CFG_DIR). */
    const PLUGIN_CFG_DIR = '/boot/config/plugins/unraid-aicliagents';
    /** Where the snapshotted plugin settings live inside the managed home (home-relative). */
    const PLUGIN_BACKUP_REL = '.aicli/plugin-backup';
    /**
     * The ONLY plugin-config files snapshotted for backup — tiny + secret-free. Deny-by-
     * default: secrets.cfg, the secrets/ dir, ssh_keys.json, ~/.aicli/.exported_keys_*,
     * envs/, and especially persistence/ (GIGABYTES of squashfs) are NEVER copied.
     */
    const PLUGIN_BACKUP_FILES = ['unraid-aicliagents.cfg', 'versions.json', 'secrets_freeform_keys.json'];

    /** @var bool|null memoised git-availability probe */
    private static $gitAvailable = null;

    // ------------------------------------------------------------------
    // availability / gating
    // ------------------------------------------------------------------

    /**
     * `command -v git` equivalent as a pure-PHP PATH walk (no subprocess, no
     * shell). Stock Unraid does not bundle git and neither does this plugin —
     * the UI surfaces "install git via NerdTools" guidance when this is false.
     */
    public static function gitAvailable(): bool {
        if (self::$gitAvailable !== null) return self::$gitAvailable;
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        foreach (explode(':', $path) as $dir) {
            if ($dir !== '' && @is_executable(rtrim($dir, '/') . '/git')) {
                return self::$gitAvailable = true;
            }
        }
        return self::$gitAvailable = false;
    }

    /** Opt-in master switch (config key hub_git_enabled; absent = off). */
    public static function enabled(): bool {
        if (getenv('AICLI_HUB_GIT_TEST_ENABLED') === '1') return true;
        $config = ConfigService::getConfig();
        return (string)($config['hub_git_enabled'] ?? '0') === '1';
    }

    /** Auto-commit toggle — defaults ON once the git layer is enabled. */
    public static function autocommitEnabled(): bool {
        if (!self::enabled()) return false;
        if (getenv('AICLI_HUB_GIT_TEST_ENABLED') === '1') return true;
        $config = ConfigService::getConfig();
        return (string)($config['hub_git_autocommit'] ?? '1') === '1';
    }

    /** Repo initialized at the (mounted) home? */
    public static function initialized(): bool {
        $home = self::home();
        return $home !== null && is_dir("$home/.git");
    }

    private static function debounceSeconds(): int {
        $env = getenv('AICLI_HUB_GIT_DEBOUNCE_S');
        if ($env !== false && $env !== '' && is_numeric($env)) return max(0, (int)$env);
        return self::DEBOUNCE_S;
    }

    /** Mounted home path or null (same gate as HubProjector). */
    private static function home(): ?string {
        $info = HubProjector::resolveHome();
        return $info['ok'] ? $info['home'] : null;
    }

    private static function user(): string {
        $config = ConfigService::getConfig();
        $user = (string)($config['user'] ?? 'root');
        return $user === '' ? 'root' : $user;
    }

    private static function lockFile(): string {
        // MUST stay identical to TaskService::persistHome's lock path — this is
        // the interlock that keeps git from interleaving with a bake.
        $user = preg_replace('/[^a-zA-Z0-9_-]/', '_', self::user());
        @mkdir('/tmp/unraid-aicliagents', 0755, true);
        return "/tmp/unraid-aicliagents/init_{$user}.lock";
    }

    private static function pendingMarker(): string {
        $user = preg_replace('/[^a-zA-Z0-9_-]/', '_', self::user());
        @mkdir('/tmp/unraid-aicliagents', 0755, true);
        return "/tmp/unraid-aicliagents/hub_git_pending_{$user}";
    }

    // ------------------------------------------------------------------
    // git plumbing — proc_open array form ONLY, no shell anywhere
    // ------------------------------------------------------------------

    /**
     * Run git against the home repo. Identity and safe.directory ride along as
     * -c flags on EVERY invocation: the overlay-mounted home is typically owned
     * by the session user while the web ctx runs as root, which trips git's
     * dubious-ownership check without safe.directory; the inline identity means
     * no global gitconfig is needed or touched.
     * @param string[] $args
     * @param array<string,string>|null $extraEnv extra env (e.g. GIT_ASKPASS + PAT)
     * @return array{rc:int,out:string,err:string}
     */
    // nosemgrep: php.lang.security.exec-use.exec-use
    private static function git(array $args, ?array $extraEnv = null, int $timeoutS = 30, ?string $stdin = null): array {
        $home = self::home();
        if ($home === null) return ['rc' => -1, 'out' => '', 'err' => 'home_unavailable'];
        $argv = array_merge([
            'git', '-C', $home,
            '-c', "safe.directory=$home",
            '-c', 'user.name=AICli Hub',
            '-c', 'user.email=aicli@localhost',
            '-c', 'init.defaultBranch=' . self::BRANCH,
            '-c', 'core.fileMode=false',
        ], $args);

        $env = null;
        if ($extraEnv !== null) {
            // proc_open's env REPLACES the environment — rebuild a minimal one.
            $env = array_merge([
                'PATH' => (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
                'HOME' => $home,
            ], $extraEnv);
        }

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) $desc[0] = ['pipe', 'r'];
        // nosemgrep: php.lang.security.exec-use.exec-use
        $proc = @proc_open($argv, $desc, $pipes, $home, $env);
        if (!is_resource($proc)) return ['rc' => -1, 'out' => '', 'err' => 'proc_open failed'];
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
            unset($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = ''; $err = '';
        $deadline = microtime(true) + $timeoutS;
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
                proc_close($proc);
                return ['rc' => -2, 'out' => trim($out), 'err' => "git timed out after {$timeoutS}s"];
            }
            usleep(20000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        proc_close($proc);
        // proc_get_status's exitcode is only valid on the FIRST running=false
        // observation — which is exactly the $status the loop broke on.
        $rc = (int)$status['exitcode'];
        return ['rc' => $rc, 'out' => trim($out), 'err' => trim($err)];
    }

    // ------------------------------------------------------------------
    // .gitignore generation (pure + testable)
    // ------------------------------------------------------------------

    /**
     * Deny-by-default .gitignore managed block.
     *
     * Semantics (verified by GitHomeServiceTest leak guards):
     *  - `*` denies every FILE by default — a future vendor token file cannot
     *    leak by omission; new vendors require an explicit `!` whitelist entry.
     *  - the directory re-include rule (bang-star-slash, second rule below)
     *    re-includes every DIRECTORY so git descends and the file-level
     *    whitelist can match (gitignore never un-ignores a file whose
     *    parent directory is still ignored — `!.claude/settings.json` alone
     *    would be dead under a bare `*`). Directories themselves are never
     *    tracked by git, so this re-include leaks nothing.
     *  - The OAuth trio is re-denied at the END (last match wins) with named
     *    comments so the exclusion is self-documenting even though `*` already
     *    covers them — and so no future whitelist glob can accidentally
     *    out-rank them.
     */
    public static function generateGitignore(): string {
        // DERIVE the whitelist from the projector registry so the backup can NEVER drift
        // from what is injected. THIS IS THE FIX for the audit-era bug where newly-wired
        // surfaces (~/.kilo/rules, ~/.agents/skills, OpenCode AGENTS.md, …) were silently
        // excluded from the backup. Every MCP / instruction / skills / commands path the hub
        // projects is whitelisted; the OAuth trio is re-denied last (see below). NOTE: the
        // projected per-agent MCP files can carry RESOLVED secret env values — versioning
        // them is a documented tradeoff; the canonical .aicli/hub store keeps the placeholder
        // form regardless.
        $oauthDeny = ['.claude.json', '.gemini/mcp-oauth-tokens.json', '.codex/auth.json'];
        // .aicli/hub/** = canonical store; plugin-backup/** = snapshotted /boot settings;
        // workspaces.json = the (home-resident) workspace definitions. NOT .aicli/** wholesale
        // — that would sweep in ~/.aicli/.exported_keys_* (secrets) and other state.
        $whitelist = ['.aicli/hub/**', '.aicli/plugin-backup/**', '.aicli/workspaces.json',
                      '.claude/settings.json', '.claude/agents/**'];
        foreach (HubProjector::supportedVendors() as $p)   $whitelist[] = $p->relPath();              // MCP files
        foreach (HubProjector::instructionVendors() as $p) $whitelist[] = $p->relPath();              // instruction files
        // File-path-convention policy files (docs/specs/AGENT_FILE_PATH_CONVENTION.md): the fence
        // agents share relPath() with their instruction-file entry above (de-duped by the
        // array_unique below); Kilo's dedicated ~/.kilo/rules/aicli-file-paths.md is the only
        // genuinely NEW path this adds.
        foreach (HubProjector::policyInstructionVendors() as $p) $whitelist[] = $p->relPath();
        foreach (HubProjector::treeVendors() as $p)        $whitelist[] = rtrim($p->relPath(), '/') . '/**'; // skills/commands dirs
        // Drop anything that is OAuth-excluded (e.g. ~/.claude.json is also Claude's MCP path).
        $whitelist = array_values(array_filter(array_unique($whitelist), fn($x) => !in_array($x, $oauthDeny, true)));
        sort($whitelist);

        $lines = [
            self::GI_FENCE_OPEN,
            '# Deny-by-default: only explicitly whitelisted paths ever enter git.',
            '*',
            '# Re-include directories so git descends (files stay denied by *).',
            '!*/',
            '',
            '# The backup machinery itself',
            '!.gitignore',
            '',
            '# Whitelist DERIVED from the Config-Hub projector registry (canonical .aicli/hub',
            '# store + Claude config + every projected MCP / instruction / skills / commands',
            '# surface) so it can never drift from what is injected. Adding a new agent surface',
            '# auto-includes it in the backup.',
        ];
        foreach ($whitelist as $path) $lines[] = '!' . $path;
        $lines = array_merge($lines, [
            '',
            '# ---- OAuth / credential exclusions (explicit re-denies; LAST = wins) ----',
            '# ~/.claude.json co-locates the Claude Code OAuth token with user-level',
            '# settings/MCP — excluded ENTIRELY. The MCP config is recoverable from the',
            '# canonical .aicli/hub store; manage Claude settings via ~/.claude/settings.json.',
            '.claude.json',
            '# ~/.gemini/mcp-oauth-tokens.json — Gemini CLI OAuth tokens.',
            '.gemini/mcp-oauth-tokens.json',
            '# ~/.codex/auth.json — Codex CLI auth credentials.',
            '.codex/auth.json',
            self::GI_FENCE_CLOSE,
        ]);
        return implode("\n", $lines) . "\n";
    }

    /**
     * Write/refresh ~/.gitignore: a pre-existing user file keeps its own rules
     * OUTSIDE the fence (block replaced in place when present, appended when
     * not). The managed block sits LAST so deny-by-default cannot be defeated
     * by earlier user rules.
     */
    private static function writeGitignore(string $home): bool {
        $file = "$home/.gitignore";
        $block = self::generateGitignore();
        $existing = is_file($file) ? (string)@file_get_contents($file) : '';
        $openPos = strpos($existing, self::GI_FENCE_OPEN);
        $closePos = strpos($existing, self::GI_FENCE_CLOSE);
        if ($openPos !== false && $closePos !== false && $closePos > $openPos) {
            $before = substr($existing, 0, $openPos);
            $after = substr($existing, $closePos + strlen(self::GI_FENCE_CLOSE));
            $content = rtrim($before . ltrim($after, "\n"));
            $content = ($content === '' ? '' : $content . "\n\n") . $block;
        } else {
            $content = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block;
        }
        return \AICliAgents\Services\AtomicWriteService::write($file, $content);
    }

    // ------------------------------------------------------------------
    // secret clean/smudge filter (resolved secrets <-> {KEY} placeholders)
    // ------------------------------------------------------------------

    /** Absolute path to the clean/smudge filter php script (deployed + in-repo). */
    public static function filterScriptPath(): string {
        return dirname(__DIR__, 3) . '/scripts/user/git-secret-filter.php';
    }

    /**
     * Pure clean/smudge transform (the filter script delegates here so it is unit-testable).
     *  - clean:  resolved secret VALUE -> {KEY}. Longest values first so a value that is a
     *            substring of another isn't partially clobbered; empty / <6-char values are
     *            skipped (a tiny "secret" would cause coincidental false-positive replaces).
     *  - smudge: {KEY} -> resolved secret value.
     * Only vault-shaped keys ([A-Z][A-Z0-9_]+) are considered.
     * @param array<string,mixed> $secrets KEY => value
     */
    public static function applySecretFilter(string $mode, string $content, array $secrets): string {
        $valid = [];
        foreach ($secrets as $k => $v) {
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', (string)$k)) $valid[(string)$k] = (string)$v;
        }
        if ($mode === 'clean') {
            $pairs = [];
            foreach ($valid as $k => $v) {
                if ($v === '' || strlen($v) < 6) continue;
                $pairs[] = [$v, '{' . $k . '}'];
            }
            usort($pairs, static fn($a, $b) => strlen($b[0]) <=> strlen($a[0]));
            foreach ($pairs as [$val, $ph]) $content = str_replace($val, $ph, $content);
            return $content;
        }
        foreach ($valid as $k => $v) $content = str_replace('{' . $k . '}', $v, $content);
        return $content;
    }

    /**
     * Managed .gitattributes: route every per-agent MCP file (which HubProjector resolves
     * {SECRET} placeholders into) through the clean/smudge filter, so the COMMITTED blob is
     * sanitized while the working tree stays resolved. DERIVED from the MCP projector
     * registry (minus the OAuth-excluded ~/.claude.json) so it can't drift.
     */
    public static function generateGitAttributes(): string {
        $oauth = ['.claude.json', '.gemini/mcp-oauth-tokens.json', '.codex/auth.json'];
        $paths = [];
        foreach (HubProjector::supportedVendors() as $p) {
            if (in_array($p->relPath(), $oauth, true)) continue;
            $paths[] = $p->relPath();
        }
        $paths = array_values(array_unique($paths));
        sort($paths);
        $lines = [
            self::GI_FENCE_OPEN,
            '# Sanitize resolved secrets out of the COMMITTED per-agent MCP files (the working',
            '# tree stays resolved). Filter defined in .git/config (filter.' . self::SECRET_FILTER . '.*).',
        ];
        foreach ($paths as $rel) $lines[] = $rel . ' filter=' . self::SECRET_FILTER;
        $lines[] = self::GI_FENCE_CLOSE;
        return implode("\n", $lines) . "\n";
    }

    /** Write/refresh ~/.gitattributes (same managed-fence discipline as .gitignore). */
    private static function writeGitAttributes(string $home): bool {
        $file = "$home/.gitattributes";
        $block = self::generateGitAttributes();
        $existing = is_file($file) ? (string)@file_get_contents($file) : '';
        $openPos = strpos($existing, self::GI_FENCE_OPEN);
        $closePos = strpos($existing, self::GI_FENCE_CLOSE);
        if ($openPos !== false && $closePos !== false && $closePos > $openPos) {
            $before = substr($existing, 0, $openPos);
            $after = substr($existing, $closePos + strlen(self::GI_FENCE_CLOSE));
            $content = rtrim($before . ltrim($after, "\n"));
            $content = ($content === '' ? '' : $content . "\n\n") . $block;
        } else {
            $content = ($existing === '' ? '' : rtrim($existing) . "\n\n") . $block;
        }
        return \AICliAgents\Services\AtomicWriteService::write($file, $content);
    }

    /**
     * Register the clean/smudge filter in THIS repo's .git/config (local, never committed).
     * `required=true` makes git ABORT if the filter errors — it can never fall back to the
     * raw secret-bearing blob. Uses PHP_BINARY so the same interpreter resolves on the CLI.
     */
    private static function configureSecretFilter(): void {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::filterScriptPath());
        self::git(['config', 'filter.' . self::SECRET_FILTER . '.clean',  $cmd . ' clean']);
        self::git(['config', 'filter.' . self::SECRET_FILTER . '.smudge', $cmd . ' smudge']);
        self::git(['config', 'filter.' . self::SECRET_FILTER . '.required', 'true']);
    }

    // ------------------------------------------------------------------
    // plugin-settings backup/restore (the small, secret-free config)
    // ------------------------------------------------------------------

    private static function pluginCfgDir(): string {
        $env = getenv('AICLI_PLUGIN_CFG_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : self::PLUGIN_CFG_DIR;
    }

    /**
     * Snapshot the curated, secret-free plugin settings (PLUGIN_BACKUP_FILES) from flash
     * into ~/.aicli/plugin-backup so they ride along in the next commit. Runs in the plugin's
     * commit path (root web ctx — the only context that can read the 0700 plugin dir on
     * flash). NEVER touches secrets or the GB-scale persistence/ squashfs. A source file that
     * has gone away is dropped from the backup so the repo mirrors the live settings.
     */
    public static function snapshotPluginSettings(string $home): void {
        $src = self::pluginCfgDir();
        $dst = rtrim($home, '/') . '/' . self::PLUGIN_BACKUP_REL;
        if (!is_dir($dst)) @mkdir($dst, 0700, true);
        foreach (self::PLUGIN_BACKUP_FILES as $f) {
            $s = "$src/$f"; $d = "$dst/$f";
            if (is_file($s)) { @copy($s, $d); @chmod($d, 0600); }
            elseif (is_file($d)) { @unlink($d); }
        }
    }

    /**
     * Restore the snapshotted plugin settings back to flash — a DELIBERATE, user-confirmed
     * action (never automatic on pull: writing live plugin config mid-operation is a
     * foot-gun). Copies only PLUGIN_BACKUP_FILES that exist in the backup; the workspace
     * definitions live in the home (~/.aicli/workspaces.json) and are restored by the
     * checkout itself. @return array{status:string,restored:string[],message?:string}
     */
    public static function restorePluginSettings(): array {
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        $home = self::home();
        if ($home === null) return ['status' => 'error', 'reason' => 'home_unavailable', 'message' => 'agent home is not mounted'];
        $src = rtrim($home, '/') . '/' . self::PLUGIN_BACKUP_REL;
        if (!is_dir($src)) return ['status' => 'error', 'message' => 'no plugin-settings backup found — commit once first'];
        $dst = self::pluginCfgDir();
        if (!is_dir($dst)) return ['status' => 'error', 'message' => 'plugin config dir missing'];
        $restored = [];
        foreach (self::PLUGIN_BACKUP_FILES as $f) {
            if (is_file("$src/$f") && @copy("$src/$f", "$dst/$f")) { @chmod("$dst/$f", 0600); $restored[] = $f; }
        }
        LogService::log('hub git: restored plugin settings [' . implode(',', $restored) . ']', LogService::LOG_INFO, 'GitHomeService');
        return ['status' => 'ok', 'restored' => $restored];
    }

    // ------------------------------------------------------------------
    // lifecycle
    // ------------------------------------------------------------------

    /**
     * Initialize the git layer: write the deny-by-default .gitignore, git init,
     * initial commit. Idempotent — re-running refreshes the managed .gitignore
     * block and commits it if it changed, but never re-inits.
     * Does NOT flip the hub_git_enabled config key — the AJAX handler owns that
     * (keeps this service runnable under the test env hooks without /boot).
     */
    public static function init(): array {
        if (!self::gitAvailable()) {
            return ['status' => 'error', 'reason' => 'git_unavailable',
                    'message' => 'git is not installed — install it via NerdTools (Community Applications) to enable config backup.'];
        }
        $home = self::home();
        if ($home === null) {
            return ['status' => 'error', 'reason' => 'home_unavailable',
                    'message' => 'The agent home overlay is not mounted — start a session or the array, then retry.'];
        }
        if (!self::writeGitignore($home)) {
            return ['status' => 'error', 'message' => 'failed to write .gitignore'];
        }

        $fresh = !is_dir("$home/.git");
        if ($fresh) {
            $r = self::git(['init', '-b', self::BRANCH]);
            if ($r['rc'] !== 0) {
                // Older git without -b: fall back to plain init (init.defaultBranch
                // -c flag still applies on ≥2.28; ancient gits land on master and
                // push/pull below would need the branch renamed — log it).
                $r = self::git(['init']);
                if ($r['rc'] !== 0) {
                    return ['status' => 'error', 'message' => 'git init failed: ' . $r['err']];
                }
            }
        }

        // Register the secret clean/smudge filter (.git/config, local) + the .gitattributes
        // that routes per-agent MCP files through it, BEFORE the commit so the committed
        // blobs are sanitized. Idempotent on re-init.
        self::configureSecretFilter();
        if (!self::writeGitAttributes($home)) {
            return ['status' => 'error', 'message' => 'failed to write .gitattributes'];
        }

        $commit = self::lockedCommit($fresh ? 'hub: initial config backup' : 'hub: refresh managed config');
        if (($commit['status'] ?? '') === 'busy') return $commit;

        self::fixOwnership($home);
        LogService::log('hub git: init ' . ($fresh ? 'created repo' : 'refreshed existing repo') . " at $home", LogService::LOG_INFO, 'GitHomeService');
        return ['status' => 'ok', 'created' => $fresh];
    }

    /**
     * Status surface for the UI (and the debounce flush consumer: a due pending
     * marker is flushed here, so the tab's status poll publishes coalesced
     * auto-commits without any daemon involvement).
     */
    public static function status(): array {
        $out = [
            'status' => 'ok',
            'gitAvailable' => self::gitAvailable(),
            'enabled' => self::enabled(),
            'autocommit' => self::autocommitEnabled(),
            'initialized' => false,
            'branch' => self::BRANCH,
            'homeAvailable' => self::home() !== null,
            'dirty' => 0,
            'commits' => 0,
            'lastCommit' => null,
            'pending' => false,
            'remote' => '',
            'tokenSet' => false,
        ];
        if (!$out['gitAvailable'] || !$out['homeAvailable'] || !self::initialized()) return $out;
        $out['initialized'] = true;

        // Flush a due pending auto-commit on the poll path (debounce consumer).
        self::flushPendingIfDue();

        $st = self::git(['status', '--porcelain']);
        if ($st['rc'] === 0) $out['dirty'] = $st['out'] === '' ? 0 : count(explode("\n", $st['out']));
        $cnt = self::git(['rev-list', '--count', 'HEAD']);
        if ($cnt['rc'] === 0) $out['commits'] = (int)$cnt['out'];
        $last = self::git(['log', '-1', '--pretty=format:%H%x1f%h%x1f%ct%x1f%s']);
        if ($last['rc'] === 0 && $last['out'] !== '') {
            $f = explode("\x1f", $last['out']);
            if (count($f) === 4) {
                $out['lastCommit'] = ['hash' => $f[0], 'short' => $f[1], 'ts' => (int)$f[2], 'subject' => $f[3]];
            }
        }
        $out['pending'] = is_file(self::pendingMarker());
        $remote = self::git(['remote', 'get-url', 'origin']);
        if ($remote['rc'] === 0) $out['remote'] = $remote['out'];
        $out['tokenSet'] = (string)(SecretService::getAgentSecrets()[self::TOKEN_KEY] ?? '') !== '';
        return $out;
    }

    // ------------------------------------------------------------------
    // commits (debounced auto-commit + manual)
    // ------------------------------------------------------------------

    /**
     * Debounced auto-commit hook, called by the hub save paths AFTER their own
     * successful writes. Mechanism (documented choice): every call appends the
     * summary (names only, never values) to a /tmp pending marker; if the last
     * commit is older than the 30 s debounce window the marker is flushed into
     * ONE commit immediately, otherwise the marker waits for the NEXT
     * commitIfEnabled() call or the UI's status() poll to flush it. Bursts of
     * saves therefore coalesce into a single commit ≥30 s apart with all their
     * summaries in the commit body. No-op (never an error) when the layer is
     * off, git is absent, the repo is uninitialized, or the home is unmounted.
     */
    public static function commitIfEnabled(string $summary): void {
        if (!self::autocommitEnabled() || !self::gitAvailable() || !self::initialized()) return;
        $summary = self::sanitizeMessage($summary);
        $marker = self::pendingMarker();
        $existing = is_file($marker) ? (string)@file_get_contents($marker) : '';
        if (strpos($existing, $summary . "\n") === false) {
            @file_put_contents($marker, $existing . $summary . "\n");
            @chmod($marker, 0600);
        }
        self::flushPendingIfDue();
    }

    /** Flush the pending marker into one commit when the debounce has elapsed. */
    private static function flushPendingIfDue(): void {
        if (!self::autocommitEnabled()) return;
        $marker = self::pendingMarker();
        if (!is_file($marker)) return;
        $last = self::git(['log', '-1', '--pretty=format:%ct']);
        $lastTs = ($last['rc'] === 0 && $last['out'] !== '') ? (int)$last['out'] : 0;
        if ((time() - $lastTs) < self::debounceSeconds()) return; // still inside the window
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string)@file_get_contents($marker)))));
        if (empty($lines)) { @unlink($marker); return; }
        $subject = count($lines) === 1 ? $lines[0] : ('hub: ' . count($lines) . ' coalesced changes');
        $body = count($lines) === 1 ? '' : implode("\n", $lines);
        $r = self::lockedCommit($subject, $body);
        if (($r['status'] ?? '') !== 'busy') {
            // committed or nothing-to-commit → marker consumed either way
            @unlink($marker);
        }
        // busy (bake in flight): marker stays, a later call retries.
    }

    /**
     * Manual commit (hub_git_commit). Bypasses the debounce; still takes the
     * per-user bake interlock.
     */
    public static function commit(string $message): array {
        if (!self::gitAvailable()) return ['status' => 'error', 'reason' => 'git_unavailable', 'message' => 'git is not installed'];
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        return self::lockedCommit(self::sanitizeMessage($message));
    }

    /**
     * add -A + commit under the SAME per-user lock TaskService::persistHome
     * takes, so a commit can never interleave with a bake (and vice versa —
     * the committed tree is always consistent with what gets baked). Non-
     * blocking with a short retry; a held lock returns busy rather than
     * stalling the web request behind a long bake.
     */
    private static function lockedCommit(string $subject, string $body = ''): array {
        $fp = @fopen(self::lockFile(), 'w+');
        if (!$fp) return ['status' => 'error', 'message' => 'cannot open interlock file'];
        $got = false;
        for ($i = 0; $i < 10; $i++) { // ~1 s of patience
            if (flock($fp, LOCK_EX | LOCK_NB)) { $got = true; break; }
            usleep(100000);
        }
        if (!$got) {
            fclose($fp);
            LogService::log('hub git: commit deferred — bake interlock held', LogService::LOG_DEBUG, 'GitHomeService');
            return ['status' => 'busy', 'message' => 'a home bake is in flight — the commit will retry'];
        }
        try {
            // Refresh the deny-by-default whitelist AND the secret-filter attributes from
            // the CURRENT projector registry BEFORE staging — so newly-wired surfaces (and
            // whitelist/filter code fixes) take effect on every commit, not only at init().
            // Without this, an existing repo keeps a stale whitelist and silently never
            // backs up new agent surfaces (or leaves new MCP files unsanitized).
            $home = self::home();
            if ($home !== null) {
                self::snapshotPluginSettings($home); // curated /boot settings -> ~/.aicli/plugin-backup
                self::writeGitignore($home);
                self::configureSecretFilter();
                self::writeGitAttributes($home);
            }
            $add = self::git(['add', '-A']);
            if ($add['rc'] !== 0) return ['status' => 'error', 'message' => 'git add failed: ' . $add['err']];
            $st = self::git(['status', '--porcelain']);
            if ($st['rc'] === 0 && $st['out'] === '') {
                return ['status' => 'ok', 'committed' => false, 'message' => 'nothing to commit'];
            }
            $msg = $subject . ($body !== '' ? "\n\n" . $body : '');
            $r = self::git(['commit', '-m', $msg]);
            if ($r['rc'] !== 0) return ['status' => 'error', 'message' => 'git commit failed: ' . $r['err']];
            LogService::log("hub git: commit '" . $subject . "'", LogService::LOG_INFO, 'GitHomeService');
            return ['status' => 'ok', 'committed' => true];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ------------------------------------------------------------------
    // history / diff / restore
    // ------------------------------------------------------------------

    /** Last $limit commits, newest first, each with its touched-file list. */
    public static function log(int $limit = 20): array {
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        $limit = max(1, min(50, $limit));
        $r = self::git(['log', "-$limit", '--name-only', '--pretty=format:%x01%H%x1f%h%x1f%ct%x1f%s']);
        if ($r['rc'] !== 0) return ['status' => 'error', 'message' => 'git log failed: ' . $r['err']];
        $commits = [];
        foreach (array_filter(explode("\x01", $r['out'])) as $rec) {
            $lines = explode("\n", trim($rec));
            $f = explode("\x1f", array_shift($lines));
            if (count($f) !== 4) continue;
            $commits[] = [
                'hash' => $f[0], 'short' => $f[1], 'ts' => (int)$f[2], 'subject' => $f[3],
                'files' => array_values(array_filter(array_map('trim', $lines))),
            ];
        }
        return ['status' => 'ok', 'commits' => $commits];
    }

    /**
     * Diff of a single file: CURRENT working tree vs its content at $ref
     * (i.e. the restore preview — what Restore would change back). Output is
     * size-capped; the UI renders it in an escaped <pre>.
     */
    public static function diffFile(string $path, string $ref): array {
        $err = self::validatePathRef($path, $ref);
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        $r = self::git(['diff', $ref, '--', $path]);
        if ($r['rc'] !== 0 && $r['rc'] !== 1) { // diff exits 1 with differences under some configs
            return ['status' => 'error', 'message' => 'git diff failed: ' . $r['err']];
        }
        $diff = $r['out'];
        if (strlen($diff) > 262144) $diff = substr($diff, 0, 262144) . "\n… (diff truncated at 256 KB)";
        return ['status' => 'ok', 'diff' => $diff, 'identical' => $diff === ''];
    }

    /**
     * Restore one file from a past commit: checkout the single path, then
     * auto-commit "hub: restore <path> from <short-ref>" so the restore itself
     * is in history (and undoable). Guards: the path must be inside the
     * whitelist (i.e. NOT gitignored — otherwise this endpoint would be a
     * write-to-arbitrary-home-path primitive) and must exist at $ref.
     */
    public static function restoreFile(string $path, string $ref): array {
        $err = self::validatePathRef($path, $ref);
        if ($err !== null) return ['status' => 'error', 'message' => $err];
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];

        // Whitelist guard: check-ignore rc 0 == ignored == NOT restorable.
        $ig = self::git(['check-ignore', '-q', '--', $path]);
        if ($ig['rc'] === 0) {
            return ['status' => 'error', 'message' => "refusing to restore '$path' — outside the backup whitelist"];
        }
        $exists = self::git(['cat-file', '-e', "$ref:$path"]);
        if ($exists['rc'] !== 0) {
            return ['status' => 'error', 'message' => "'$path' does not exist at $ref"];
        }

        $fp = @fopen(self::lockFile(), 'w+');
        if (!$fp) return ['status' => 'error', 'message' => 'cannot open interlock file'];
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return ['status' => 'busy', 'message' => 'a home bake is in flight — retry shortly'];
        }
        try {
            $co = self::git(['checkout', $ref, '--', $path]);
            if ($co['rc'] !== 0) return ['status' => 'error', 'message' => 'git checkout failed: ' . $co['err']];
            $short = substr($ref, 0, 12);
            self::git(['add', '-A', '--', $path]);
            $st = self::git(['status', '--porcelain']);
            if ($st['rc'] === 0 && $st['out'] !== '') {
                $c = self::git(['commit', '-m', "hub: restore $path from $short"]);
                if ($c['rc'] !== 0) return ['status' => 'error', 'message' => 'restored but commit failed: ' . $c['err']];
            }
            self::fixOwnership(dirname(rtrim((string)self::home(), '/') . '/' . $path));
            LogService::log("hub git: restored $path from $short", LogService::LOG_INFO, 'GitHomeService');
            return ['status' => 'ok'];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ------------------------------------------------------------------
    // remote (explicit push only — never automatic)
    // ------------------------------------------------------------------

    /**
     * Set (or replace) the origin remote. http:// and https:// are both accepted
     * (http for trusted LAN git servers — see the cleartext caveat below). URLs with
     * embedded userinfo (user:pass@host) are refused on EITHER scheme: the token is
     * injected at push/pull time via GIT_ASKPASS env, never written into the URL (which
     * would land in plaintext in .git/config on disk). The two failure modes are
     * reported distinctly so an http URL isn't mislabelled an "embedded credentials"
     * error.
     */
    public static function setRemote(string $url): array {
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        $url = trim($url);
        // Embedded credentials — refused on both schemes (the PAT is supplied via
        // GIT_ASKPASS, so it must NOT be in the URL). Reported separately from the
        // scheme check so the message is accurate.
        if (strpos($url, '@') !== false) {
            return ['status' => 'error', 'message' => 'remove the credentials from the URL — your token is added automatically and securely at push time (via GIT_ASKPASS), so the URL must be the bare repo address with no user:token@ part'];
        }
        if (!preg_match('#^https?://[A-Za-z0-9._~:/?\#\[\]!$&\'()*+,;=%-]+$#', $url)) {
            return ['status' => 'error', 'message' => 'remote must be an http:// or https:// URL'];
        }
        $insecure = (strncmp($url, 'http://', 7) === 0);
        self::git(['remote', 'remove', 'origin']); // idempotent — ignore failure
        $r = self::git(['remote', 'add', 'origin', $url]);
        if ($r['rc'] !== 0) return ['status' => 'error', 'message' => 'remote add failed: ' . $r['err']];
        LogService::log('hub git: remote origin set (' . ($insecure ? 'http' : 'https') . ')', LogService::LOG_INFO, 'GitHomeService');
        // `insecure` lets the UI warn that an http remote sends the token in cleartext.
        return ['status' => 'ok', 'insecure' => $insecure];
    }

    /** Explicit push (UI button only — auto-push is deliberately not a thing). */
    public static function push(): array {
        return self::remoteOp(['push', '-u', 'origin', self::BRANCH]);
    }

    /** Fast-forward-only pull (service-level capability; no auto consumer). */
    public static function pull(): array {
        return self::remoteOp(['pull', '--ff-only', 'origin', self::BRANCH]);
    }

    private static function remoteOp(array $args): array {
        if (!self::initialized()) return ['status' => 'error', 'message' => 'git layer not initialized'];
        $remote = self::git(['remote', 'get-url', 'origin']);
        if ($remote['rc'] !== 0) return ['status' => 'error', 'message' => 'no remote configured — set a remote URL first'];
        $token = (string)(SecretService::getAgentSecrets()[self::TOKEN_KEY] ?? '');
        if ($token === '') {
            return ['status' => 'error', 'message' => 'no ' . self::TOKEN_KEY . ' in the secrets vault — save a token first'];
        }
        $askpass = self::askpassPath();
        if (!is_file($askpass)) return ['status' => 'error', 'message' => 'git-askpass helper missing'];
        @chmod($askpass, 0755);
        $r = self::git($args, [
            'GIT_ASKPASS' => $askpass,
            'GIT_TERMINAL_PROMPT' => '0',
            'AICLI_GIT_PAT' => $token,      // env-only — never argv, never disk
        ], 120);
        if ($r['rc'] !== 0) {
            // stderr may quote the URL but never the PAT (askpass-fed).
            return ['status' => 'error', 'message' => 'git ' . $args[0] . ' failed: ' . $r['err']];
        }
        LogService::log('hub git: ' . $args[0] . ' origin/' . self::BRANCH . ' ok', LogService::LOG_INFO, 'GitHomeService');
        return ['status' => 'ok', 'output' => $r['out'] !== '' ? $r['out'] : $r['err']];
    }

    private static function askpassPath(): string {
        // src/includes/services/hub → up 3 = src (works deployed and in-repo).
        return dirname(__DIR__, 3) . '/scripts/user/git-askpass.sh';
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Commit-message hygiene: strip control chars, collapse, cap length. */
    private static function sanitizeMessage(string $msg): string {
        $msg = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $msg);
        $msg = trim(preg_replace('/\s{2,}/', ' ', (string)$msg));
        if ($msg === '') $msg = 'hub: update';
        return strlen($msg) > 200 ? substr($msg, 0, 197) . '…' : $msg;
    }

    /** Repo-relative path + ref validation shared by diff/restore. */
    private static function validatePathRef(string $path, string $ref): ?string {
        if ($path === '' || $path[0] === '/' || $path[0] === '-' || strpos($path, '..') !== false
            || !preg_match('#^[A-Za-z0-9._/ -]{1,256}$#', $path)) {
            return 'invalid path';
        }
        if (!preg_match('/^[0-9a-f]{4,40}$/i', $ref) && $ref !== 'HEAD') {
            return 'invalid ref (commit hash or HEAD)';
        }
        return null;
    }

    /**
     * The web ctx runs as root but the home may belong to the session user —
     * chown what we created so the user's own git/agents keep working. Mirrors
     * VendorProjector::fixOwnership. Best-effort, never fatal.
     */
    private static function fixOwnership(string $target): void {
        $home = self::home();
        if ($home === null) return;
        $st = @stat($home);
        if (!is_array($st) || (int)$st['uid'] === 0) return;
        $uid = (int)$st['uid']; $gid = (int)$st['gid'];
        foreach (["$home/.git", "$home/.gitignore"] as $p) {
            if (!file_exists($p)) continue;
            self::chownRecursive($p, $uid, $gid);
        }
        if ($target !== $home && file_exists($target)) @chown($target, $uid);
    }

    private static function chownRecursive(string $path, int $uid, int $gid): void {
        @chown($path, $uid); @chgrp($path, $gid);
        if (!is_dir($path) || is_link($path)) return;
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            self::chownRecursive("$path/$f", $uid, $gid);
        }
    }
}
