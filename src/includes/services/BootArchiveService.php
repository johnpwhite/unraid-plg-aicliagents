<?php
/**
 * <module_context>
 * Description: Archive or remove three kinds of old data from a plain home on the boot disk
 *   (docs/specs/BOOT_DISK_SPACE.md R8, issue #724). The kinds:
 *     quarantine        <home>/.aicli/quarantine/        databases that an agent set aside
 *     opencode_orphans  <home>/.local/share/aicli-opencode/ws-*   data of OpenCode workspaces that no longer exist
 *     codex_sessions    <home>/.codex/sessions/          big Codex session logs older than 14 days
 *     agent_sessions    the session logs of the other agents (Claude, Gemini, Qwen, Copilot, Grok, Kimi,
 *                       Antigravity): sessions of 1 MB or more, not changed for 14 days (#724)
 *   A fifth kind does not live in a home: superseded_layers, the layer images that a newer merged layer
 *   replaced (#724). processLayers() archives or removes them.
 *   select() finds the items that may go. measureHome() gives the sizes for the Boot disk space
 *   card. process() archives the items to a folder on a pool (copy, SHA-256 check, manifest, then
 *   remove from the home) or removes them.
 * Dependencies: HistoryStoreService (open files, allocated bytes), HistoryMoveService (pool rules,
 *   copier), MeasureDeadline, ConfigService, FavouritesService.
 * Constraints: select(), measureHome() and process() walk folders. They run in the background
 *   measure or in the detached job (scripts/storage/boot-archive.php), NEVER in a web request.
 *   Never touches a file that a process holds open. Never touches the data of a workspace that is
 *   in the workspace list or in the favourites. Never removes a file before its copy is checked
 *   and listed in the manifest. Never makes a new top-level folder on a pool.
 * </module_context>
 *
 * Archive layout:
 *   <chosen folder>/aicliagents-archive/<host>/<YYYY-MM-DD>/<user>/<kind>/<path relative to the home>
 *   <chosen folder>/aicliagents-archive/<host>/<YYYY-MM-DD>/MANIFEST-<kind>-<user>-<HHMMSS>.json
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/HistoryStoreService.php';
require_once __DIR__ . '/HistoryMoveService.php';
require_once __DIR__ . '/MeasureDeadline.php';

class BootArchiveService
{
    public const KIND_QUARANTINE = 'quarantine';
    public const KIND_OPENCODE = 'opencode_orphans';
    public const KIND_CODEX = 'codex_sessions';
    public const KIND_AGENT_SESSIONS = 'agent_sessions';
    /** Not a home kind: layer images in the persistence folders. The "user" of this kind is always LAYER_USER. */
    public const KIND_LAYERS = 'superseded_layers';
    public const LAYER_USER = 'all';
    /** The kinds that live in a plain home. measureHome() and select() work on these. */
    public const KINDS = [self::KIND_QUARANTINE, self::KIND_OPENCODE, self::KIND_CODEX, self::KIND_AGENT_SESSIONS];
    /** Every kind that a job may name. */
    public const ALL_KINDS = [self::KIND_QUARANTINE, self::KIND_OPENCODE, self::KIND_CODEX, self::KIND_AGENT_SESSIONS, self::KIND_LAYERS];

    /** A Codex log is "large" from this size (allocated bytes). */
    public const CODEX_MIN_BYTES = 1048576;
    /** A Codex log must be older than this many days. */
    public const CODEX_DAYS = 14;
    /** Data of a gone OpenCode workspace that changed inside this window stays (seconds). */
    public const RECENT_SECONDS = 86400;
    /** Free space that must remain on the target after an archive. */
    public const ROOM_MARGIN = 67108864;
    public const ARCHIVE_FOLDER = 'aicliagents-archive';
    /** The measurement keeps this many item names for the confirm of Remove. */
    public const NAMES_SHOWN = 12;

    // ---- the kinds ------------------------------------------------------------------

    /** Plain words for each kind. `info` is the text behind the (i) mark of the card line. */
    public static function describe(string $kind): array
    {
        switch ($kind) {
            case self::KIND_QUARANTINE:
                return ['label' => 'quarantine',
                    'title' => 'Quarantine folder',
                    'info' => 'Damaged agent databases that the plugin set aside and replaced. Nothing reads them. Keep a copy if you want to look at them later.',
                    'root' => '.aicli/quarantine'];
            case self::KIND_OPENCODE:
                return ['label' => 'data of deleted OpenCode workspaces',
                    'title' => 'Data of deleted OpenCode workspaces',
                    'info' => 'OpenCode keeps one database for each workspace. These workspaces are not in your workspace list or your favourites any more. Data of a workspace that exists stays.',
                    'root' => '.local/share/aicli-opencode'];
            case self::KIND_CODEX:
                return ['label' => 'large Codex session logs',
                    'title' => 'Large Codex session logs',
                    'info' => 'Codex session logs of ' . (self::CODEX_MIN_BYTES / 1048576) . ' MB or more, not changed for ' . self::CODEX_DAYS . ' days. Codex cannot resume a session that is archived or removed. These sizes are also part of the conversation history above.',
                    'root' => '.codex/sessions'];
            case self::KIND_AGENT_SESSIONS:
                return ['label' => 'large session logs of other agents',
                    'title' => 'Large session logs of other agents',
                    'info' => 'Session logs of Claude Code, Gemini, Qwen, Copilot, Grok, Kimi and Antigravity that are ' . (self::CODEX_MIN_BYTES / 1048576) . ' MB or more and not changed for ' . self::CODEX_DAYS . ' days. An agent cannot resume a session that is archived or removed. These sizes are also part of the conversation history above.',
                    'root' => '.claude/projects'];
            case self::KIND_LAYERS:
                return ['label' => 'old layer images',
                    'title' => 'Old layer images',
                    'info' => 'Layer images (.sqsh files) that a newer merged layer replaced. No mount reads them. The plugin does not put an archived image back: the copy is for safekeeping only.',
                    'root' => ''];
        }
        return ['label' => $kind, 'title' => $kind, 'info' => '', 'root' => ''];
    }

    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::ALL_KINDS, true);
    }

    /** The folders (relative to the home) that hold the data of a home kind. */
    public static function roots(string $kind): array
    {
        if ($kind === self::KIND_AGENT_SESSIONS) {
            $roots = [];
            foreach (self::sessionAgents() as $id) {
                foreach (HistoryStoreService::stores()[$id]['roots'] as $r) $roots[$r] = true;
            }
            return array_keys($roots);
        }
        $root = self::describe($kind)['root'];
        return $root === '' ? [] : [$root];
    }

    /**
     * The agents that the kind agent_sessions covers: every agent that keeps one file or folder for each
     * session AND whose layout was checked against its official source and a real home
     * (HistoryStoreService::stores(), 'verified'). Codex has its own kind.
     * @return string[]
     */
    public static function sessionAgents(): array
    {
        $ids = [];
        foreach (HistoryStoreService::stores() as $id => $s) {
            if ($id === 'codex-cli' || $s['kind'] !== 'files' || empty($s['verified'])) continue;
            $ids[] = $id;
        }
        return $ids;
    }

    /** A home id as the plugin makes it: no slash, no leading dot. */
    public static function isUser(string $user): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/D', $user) === 1;
    }

    // ---- places ----------------------------------------------------------------------

    /** The plain home of a user on the boot disk. Seams: home_persist. */
    public static function homeDir(string $user, array $seams = []): string
    {
        $hp = (string)($seams['home_persist'] ?? '');
        if ($hp === '') {
            $hp = StoragePathResolver::homePersistPathFromConfig(ConfigService::getConfig());
        }
        return rtrim($hp, '/') . '/passthrough/homes/' . $user;
    }

    /**
     * The folder names of OpenCode data that belong to a workspace in the list or in the
     * favourites: "ws-" + the first 16 characters of sha1("<user>:<path>"), the rule in
     * aicli-shell.sh. The key is made for every home user, so a path that a different user
     * opened is also safe. Seam: workspace_paths (list of paths).
     *
     * Returns null when no workspace path is known at all (an empty list, or a list that cannot be
     * read). Then the plugin cannot tell which data is in use, so the caller offers nothing.
     *
     * @return array<string,true>|null
     */
    public static function protectedWorkspaceKeys(array $users, array $seams = []): ?array
    {
        if (isset($seams['workspace_paths'])) {
            $paths = array_map('strval', (array)$seams['workspace_paths']);
        } else {
            $paths = [];
            try {
                foreach ((ConfigService::getWorkspaces()['sessions'] ?? []) as $w) {
                    if (is_array($w) && (string)($w['path'] ?? '') !== '') $paths[] = (string)$w['path'];
                }
            } catch (\Throwable $e) { /* no list: the favourites still protect */ }
            try {
                require_once __DIR__ . '/FavouritesService.php';
                foreach (FavouritesService::list() as $f) {
                    if ((string)($f['path'] ?? '') !== '') $paths[] = (string)$f['path'];
                }
            } catch (\Throwable $e) { /* nothing more to protect */ }
        }
        if (!array_filter($paths, static fn(string $p): bool => $p !== '')) return null;
        $variants = [];
        foreach ($paths as $p) {
            $variants[$p] = true;
            $variants[rtrim($p, '/')] = true;
            $real = @realpath($p);
            if ($real !== false) $variants[$real] = true;
        }
        $keys = [];
        foreach (array_unique(array_merge($users, ['root'])) as $u) {
            foreach (array_keys($variants) as $p) {
                if ($p === '') continue;
                $keys['ws-' . substr(sha1($u . ':' . $p), 0, 16)] = true;
            }
        }
        return $keys;
    }

    // ---- finding the items ---------------------------------------------------------------

    /**
     * Files under $base (a folder inside $home), without links. Stops at $deadline (a Unix time
     * with fraction).
     * @return array<int,array{rel:string,abs:string,bytes:int,mtime:int}>
     */
    private static function walk(string $home, string $base, float $deadline, bool &$partial): array
    {
        $out = [];
        if (!is_dir($base) || is_link($base)) return $out;
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
                if ((++$n & 127) === 0 && microtime(true) > $deadline) { $partial = true; break; }
                if (!$f->isFile()) continue;
                $abs = $f->getPathname();
                $st = @lstat($abs);
                if ($st === false) continue;
                $out[] = ['rel' => substr($abs, strlen($home) + 1), 'abs' => $abs,
                          'bytes' => HistoryStoreService::allocated($abs), 'mtime' => (int)$st['mtime']];
            }
        } catch (\Throwable $e) { /* an unreadable folder counts as empty */ }
        return $out;
    }

    /**
     * The items of one kind that may go now.
     *
     * $seams: now, open (callable home => list of open paths), budget (seconds), workspace_paths, users.
     *
     * @return array{units:array<int,array{id:string,rels:string[],bytes:int,mtime:int}>,skipped:array<int,array{id:string,reason:string}>,partial:bool}
     */
    public static function select(string $homeDir, string $user, string $kind, array $seams = []): array
    {
        $res = ['units' => [], 'skipped' => [], 'partial' => false];
        if (!in_array($kind, self::KINDS, true)) return $res;   // the layer kind has no home
        $home = rtrim($homeDir, '/');
        if (!is_dir($home) || is_link($home)) return $res;
        $now = (int)($seams['now'] ?? time());
        $deadline = microtime(true) + (float)($seams['budget'] ?? 120);
        $root = $home . '/' . self::describe($kind)['root'];
        $partial = false;

        if ($kind === self::KIND_QUARANTINE) {
            $groups = [];
            foreach (self::walk($home, $root, $deadline, $partial) as $f) {
                $seg = explode('/', substr($f['abs'], strlen($root) + 1));
                // <agent>/<time>/... is one item; a loose file is its own item.
                $id = count($seg) >= 3 ? $seg[0] . '/' . $seg[1] : implode('/', $seg);
                $groups[$id][] = $f;
            }
            self::collect($res, $groups, $home, $seams, 0, $now);
        } elseif ($kind === self::KIND_OPENCODE) {
            $dirs = glob($root . '/ws-*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
            // The list of workspaces is read only when there is OpenCode data to judge.
            $protected = $dirs ? self::protectedWorkspaceKeys(
                array_merge((array)($seams['users'] ?? []), [$user]), $seams) : [];
            if ($protected === null) {
                // No workspace is known. The plugin cannot tell which data is in use, so it offers none.
                $res['skipped'][] = ['id' => '(all)', 'reason' => 'The workspace list is empty or unreadable, so the plugin cannot tell which data is in use.'];
                $dirs = [];
                $protected = [];
            }
            $groups = [];
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if (is_link($dir) || preg_match('/^ws-[0-9a-f]{16}$/D', $name) !== 1) continue;
                if (isset($protected[$name])) continue;   // a workspace in the list or in the favourites
                $files = self::walk($home, $dir, $deadline, $partial);
                if ($files) $groups[$name] = $files;
            }
            self::collect($res, $groups, $home, $seams, self::RECENT_SECONDS, $now);
        } elseif ($kind === self::KIND_AGENT_SESSIONS) {
            // One item is one session of one agent (all of its files). The layout rules are the ones of
            // HistoryStoreService, so an index or a database file that is not a session is never selected.
            $groups = [];
            $cut = $now - self::CODEX_DAYS * 86400;
            foreach (self::sessionAgents() as $agentId) {
                $p2 = false;
                $units = HistoryStoreService::scanUnits($home, $agentId, $deadline, $p2);
                if ($p2) $partial = true;
                foreach ($units as $u) {
                    $files = []; $bytes = 0; $newest = 0;
                    foreach ($u['files'] as $f) {
                        $st = @lstat($f['abs']);
                        if ($st === false) continue;
                        $b = HistoryStoreService::allocated($f['abs']);
                        $files[] = ['rel' => $f['rel'], 'abs' => $f['abs'], 'bytes' => $b, 'mtime' => (int)$st['mtime']];
                        $bytes += $b;
                        $newest = max($newest, (int)$st['mtime']);
                    }
                    if (!$files || $bytes < self::CODEX_MIN_BYTES || $newest > $cut) continue;
                    $groups[$agentId . '/' . $u['key']] = $files;
                }
            }
            self::collect($res, $groups, $home, $seams, 0, $now);
        } else {
            $groups = [];
            $cut = $now - self::CODEX_DAYS * 86400;
            foreach (self::walk($home, $root, $deadline, $partial) as $f) {
                if ($f['bytes'] < self::CODEX_MIN_BYTES || $f['mtime'] > $cut) continue;
                $groups[$f['rel']] = [$f];
            }
            self::collect($res, $groups, $home, $seams, 0, $now);
        }
        $res['partial'] = $partial;
        usort($res['units'], static function ($a, $b) { return ($b['bytes'] <=> $a['bytes']) ?: strcmp($a['id'], $b['id']); });
        return $res;
    }

    /**
     * Turn file groups into items. A group with an open file, or with a file changed inside
     * $recent seconds, is skipped as a whole.
     * @param array<string,array<int,array{rel:string,abs:string,bytes:int,mtime:int}>> $groups
     */
    private static function collect(array &$res, array $groups, string $home, array $seams, int $recent, int $now): void
    {
        // The open files are read once, and only when there is an item to judge.
        $open = [];
        if ($groups) {
            $paths = isset($seams['open']) ? (array)($seams['open'])($home) : HistoryStoreService::procOpenFiles($home);
            foreach ($paths as $p) $open[(string)$p] = true;
        }
        foreach ($groups as $id => $files) {
            $bytes = 0; $newest = 0; $rels = []; $isOpen = false;
            foreach ($files as $f) {
                $bytes += $f['bytes'];
                $newest = max($newest, $f['mtime']);
                $rels[] = $f['rel'];
                if (isset($open[$f['abs']])) $isOpen = true;
            }
            sort($rels);
            if ($isOpen) { $res['skipped'][] = ['id' => (string)$id, 'reason' => 'A process uses it.']; continue; }
            if ($recent > 0 && $now - $newest < $recent) { $res['skipped'][] = ['id' => (string)$id, 'reason' => 'It changed in the last day.']; continue; }
            $res['units'][] = ['id' => (string)$id, 'rels' => $rels, 'bytes' => $bytes, 'mtime' => $newest];
        }
    }

    /**
     * The sizes for the card: kind => {bytes, units, files, skipped, partial}. Background only.
     * @return array<string,array{bytes:int,units:int,files:int,skipped:int,partial:bool}>
     */
    public static function measureHome(string $homeDir, string $user, array $seams = []): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $s = self::select($homeDir, $user, $kind, $seams + ['budget' => MeasureDeadline::remaining(120)]);
            $bytes = 0; $files = 0; $names = [];
            foreach ($s['units'] as $u) { $bytes += $u['bytes']; $files += count($u['rels']); $names[] = $u['id']; }
            $out[$kind] = ['bytes' => $bytes, 'units' => count($s['units']), 'files' => $files,
                           'skipped' => count($s['skipped']), 'partial' => $s['partial'],
                           'names' => array_slice($names, 0, self::NAMES_SHOWN)];
        }
        return $out;
    }

    /**
     * The lines of the card for one home from measureHome() figures. A kind with no item has no line.
     * @param array<string,array<string,mixed>> $figures
     * @return array<int,array<string,mixed>>
     */
    public static function cardParts(string $user, array $figures): array
    {
        $parts = [];
        foreach (self::KINDS as $kind) {
            $f = $figures[$kind] ?? null;
            if (!is_array($f) || (int)($f['units'] ?? 0) < 1) continue;
            $d = self::describe($kind);
            $note = (int)$f['units'] . ($kind === self::KIND_CODEX ? ' log(s)' : ($kind === self::KIND_AGENT_SESSIONS ? ' session(s)' : ' item(s)'))
                . ((int)($f['skipped'] ?? 0) > 0 ? ', ' . (int)$f['skipped'] . ' in use or too new, left alone' : '');
            $parts[] = [
                'key' => 'archive:' . $kind . ':' . $user, 'group' => 'home', 'user' => $user, 'kind' => $kind,
                'label' => 'Home ' . $user . ': ' . $d['label'], 'bytes' => (int)$f['bytes'],
                'note' => $note, 'info' => $d['info'], 'units' => (int)$f['units'], 'files' => (int)$f['files'],
                'names' => array_values(array_map('strval', (array)($f['names'] ?? []))),
                'action' => ['id' => 'archive_boot_data', 'label' => 'Archive…', 'enabled' => true, 'reason' => ''],
                'extra_actions' => [['id' => 'remove_boot_data', 'label' => 'Remove', 'enabled' => true, 'reason' => '']],
            ];
        }
        return $parts;
    }

    /** The line of the last measurement for a kind and a home, or null. */
    public static function lineFor(?array $data, string $kind, string $user): ?array
    {
        if ($kind === self::KIND_LAYERS) {
            // The layer images are not a line of a home. The Left over line carries their figures.
            if ($user !== self::LAYER_USER) return null;
            foreach (($data['parts'] ?? []) as $p) {
                if (($p['key'] ?? '') === 'leftover' && is_array($p['superseded'] ?? null)) {
                    return ['key' => 'archive:' . self::KIND_LAYERS . ':' . self::LAYER_USER, 'kind' => self::KIND_LAYERS,
                            'user' => self::LAYER_USER, 'label' => 'Old layer images'] + $p['superseded'];
                }
            }
            return null;
        }
        foreach (($data['parts'] ?? []) as $p) {
            if (($p['key'] ?? '') === 'archive:' . $kind . ':' . $user) return $p;
        }
        return null;
    }

    // ---- the target folder -----------------------------------------------------------------------

    /**
     * Check the folder that a person picked for an archive. The rules:
     *  - an absolute folder under /mnt/<pool> (not /mnt/user, not the boot disk, not RAM);
     *  - the pool is mounted;
     *  - the folder is at least one level below the pool and EXISTS, so the plugin never makes a
     *    new top-level folder on a pool (Unraid shows each one as a share);
     *  - the folder is not a link, and its real path stays inside the same pool.
     * Seams: mounted (fn(folder):bool).
     *
     * @return array{error:?string,folder:string}
     */
    public static function checkTarget(string $folder, array $seams = []): array
    {
        $f = rtrim(trim($folder), '/');
        $bad = static function (string $m) use ($f): array { return ['error' => $m, 'folder' => $f]; };
        if ($f === '' || !HistoryMoveService::destinationShapeOk($f)) return $bad('Pick a folder on a pool.');
        if (HistoryMoveService::normaliseSetting($f, '') === '') {
            return $bad('This place is not allowed. Pick a folder on a pool. The boot disk, RAM and /mnt/user are not allowed.');
        }
        $mounted = isset($seams['mounted']) ? (bool)($seams['mounted'])($f) : HistoryMoveService::destinationMounted($f);
        if (!$mounted) return $bad('The pool is not mounted. Start the array, then try again.');
        $root = getenv('AICLI_MNT_ROOT');
        $root = ($root !== false && $root !== '') ? rtrim($root, '/') : '/mnt';
        $parts = explode('/', substr($f, strlen($root) + 1));
        if (count($parts) < 2) {
            return $bad('Pick a folder inside the pool, not the pool itself. The plugin does not make a new top-level folder on a pool.');
        }
        if (is_link($f) || !is_dir($f)) {
            return $bad('This folder does not exist. Make the folder first. The plugin makes only the folder ' . self::ARCHIVE_FOLDER . ' inside it.');
        }
        $real = realpath($f);
        $poolReal = realpath($root . '/' . $parts[0]);
        if ($real === false || $poolReal === false || strpos($real . '/', $poolReal . '/') !== 0) {
            return $bad('This folder leads out of the pool.');
        }
        return ['error' => null, 'folder' => $real];
    }

    /** <folder>/aicliagents-archive/<host>/<date> */
    public static function archiveBase(string $realFolder, array $seams = []): string
    {
        $host = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($seams['host'] ?? (gethostname() ?: 'host'))) ?: 'host';
        $date = (string)($seams['date'] ?? date('Y-m-d', (int)($seams['now'] ?? time())));
        return rtrim($realFolder, '/') . '/' . self::ARCHIVE_FOLDER . '/' . $host . '/' . $date;
    }

    // ---- doing it ---------------------------------------------------------------------------------

    /**
     * Archive or remove the items of one kind. Background job only.
     *
     * $mode 'archive' needs $folder (checked by checkTarget()); $mode 'remove' ignores it.
     * $seams: now, open, copy (fn(src,dst):bool), free (fn(dir):int), mounted, host, date, progress (fn(array)),
     * workspace_paths, users, home_persist.
     *
     * @return array{status:string,message:string,done:int,files:int,bytes:int,skipped:array<int,array{id:string,reason:string}>,manifest:string,dest:string}
     */
    public static function process(string $homeDir, string $user, string $kind, string $mode, string $folder = '', array $seams = []): array
    {
        $res = ['status' => 'error', 'message' => '', 'done' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => [], 'manifest' => '', 'dest' => ''];
        if (!self::isKind($kind)) { $res['message'] = 'This kind of data is not known.'; return $res; }
        if (!self::isUser($user)) { $res['message'] = 'This home name is not valid.'; return $res; }
        if (!in_array($mode, ['archive', 'remove'], true)) { $res['message'] = 'The mode must be archive or remove.'; return $res; }
        if ($kind === self::KIND_LAYERS) {
            if ($user !== self::LAYER_USER) { $res['message'] = 'This home name is not valid.'; return $res; }
            return self::processLayers($mode, $folder, $seams);
        }
        $home = rtrim($homeDir, '/');
        $realHome = realpath($home);
        if ($realHome === false || is_link($home)) { $res['message'] = 'This home was not found.'; return $res; }

        $sel = self::select($home, $user, $kind, $seams);
        $res['skipped'] = $sel['skipped'];
        if (!$sel['units']) {
            $res['status'] = 'ok';
            $res['message'] = 'Nothing to ' . ($mode === 'archive' ? 'archive' : 'remove') . '.';
            return $res;
        }

        $base = '';
        $manifestPath = '';
        $entries = [];
        if ($mode === 'archive') {
            $chk = self::checkTarget($folder, $seams);
            if ($chk['error'] !== null) { $res['message'] = (string)$chk['error']; return $res; }
            $base = self::archiveBase($chk['folder'], $seams);
            $total = 0;
            foreach ($sel['units'] as $u) $total += $u['bytes'];
            // The room is checked on the chosen folder, before the plugin makes any folder in it.
            $free = isset($seams['free']) ? (int)($seams['free'])($chk['folder']) : (int)@disk_free_space($chk['folder']);
            if ($free < $total + self::ROOM_MARGIN) {
                $res['message'] = 'The target has too little room. It needs ' . BootSpaceService::human($total + self::ROOM_MARGIN) . ' free.';
                return $res;
            }
            if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
                $res['message'] = 'The archive folder could not be made.';
                return $res;
            }
            $manifestPath = $base . '/MANIFEST-' . $kind . '-' . $user . '-' . date('His', (int)($seams['now'] ?? time())) . '.json';
            $res['dest'] = $base;
        }

        $copier = $seams['copy'] ?? [HistoryMoveService::class, 'copyFile'];
        $count = count($sel['units']); $i = 0;
        foreach ($sel['units'] as $u) {
            $i++;
            if (isset($seams['progress'])) ($seams['progress'])(['done' => $i - 1, 'total' => $count, 'id' => $u['id']]);
            $why = self::unitGuard($home, $realHome, $u, $seams, false);
            if ($why !== null) { $res['skipped'][] = ['id' => $u['id'], 'reason' => $why]; continue; }

            $made = [];   // the checked copies of this item (archive mode)
            if ($mode === 'archive') {
                $why = null;
                foreach ($u['rels'] as $rel) {
                    $why = self::copyChecked($rel, $home . '/' . $rel, $base . '/' . $user . '/' . $kind . '/' . $rel, $copier, $made);
                    if ($why !== null) break;
                }
                if ($why !== null) {
                    // Nothing leaves the home for this item. Remove the copies that this run made for it.
                    foreach ($made as $m) { if ($m['fresh']) @unlink($m['dst']); }
                    $res['skipped'][] = ['id' => $u['id'], 'reason' => $why];
                    continue;
                }
                foreach ($made as $m) {
                    $entries[] = ['file' => $m['rel'], 'item' => $u['id'], 'size' => $m['size'], 'sha256' => $m['sha'],
                                  'mtime' => $m['mtime'], 'archived_at' => time()];
                }
                // The manifest lists the checked copies BEFORE any original goes.
                self::writeManifest($manifestPath, $user, $kind, $entries);
                $res['manifest'] = $manifestPath;
            }

            // The item must still be idle, and an archived file must not have changed since the copy.
            // Then the originals go.
            $why = self::unitGuard($home, $realHome, $u, $seams, true);
            if ($why === null && $mode === 'archive') {
                clearstatcache();
                foreach ($made as $m) {
                    $now = @stat($home . '/' . $m['rel']);
                    if ($now === false || (int)$now['size'] !== $m['size'] || (int)$now['mtime'] !== $m['mtime']) {
                        $why = 'A file changed during the copy.';
                        break;
                    }
                }
            }
            if ($why !== null) {
                $res['skipped'][] = ['id' => $u['id'], 'reason' => $why . ($mode === 'archive' ? ' The copy stays in the archive.' : '')];
                continue;
            }
            $gone = true;
            foreach ($u['rels'] as $rel) {
                $abs = $home . '/' . $rel;
                if (is_file($abs) && !is_link($abs)) @unlink($abs);
                clearstatcache(true, $abs);
                if (file_exists($abs) || is_link($abs)) $gone = false;
            }
            self::pruneEmptyDirs($home, $kind, $u['rels']);
            if (!$gone) { $res['skipped'][] = ['id' => $u['id'], 'reason' => 'A file could not be removed.']; continue; }
            $res['done']++;
            $res['files'] += count($u['rels']);
            $res['bytes'] += $u['bytes'];
        }
        if (isset($seams['progress'])) ($seams['progress'])(['done' => $count, 'total' => $count, 'id' => '']);
        $res['status'] = 'ok';
        $verb = $mode === 'archive' ? 'Archived' : 'Removed';
        $res['message'] = $verb . ' ' . $res['done'] . ' item(s) (' . BootSpaceService::human($res['bytes']) . ').'
            . ($res['skipped'] ? ' ' . count($res['skipped']) . ' stayed.' : '');
        return $res;
    }

    // ---- superseded layer images (R8, #724) ---------------------------------------------------------

    /**
     * The layer-image context for this moment, or null when the rule cannot run (a test without the
     * manifest). Seams: persist, home_persist, manifest_entities, used_files, in_use, now.
     * @return array<string,mixed>|null
     */
    private static function layerCtx(array $seams): ?array
    {
        $persist = rtrim((string)($seams['persist'] ?? BootSpaceService::persistPath()), '/');
        $homePersist = rtrim((string)($seams['home_persist'] ?? (isset($seams['persist'])
            ? $persist
            : StoragePathResolver::homePersistPathFromConfig(ConfigService::getConfig()))), '/');
        if ($homePersist === '') $homePersist = $persist;
        $ctx = BootSpaceService::layerContext($seams, $persist, $homePersist);
        return isset($ctx['manifest_entities']) ? $ctx : null;
    }

    /**
     * The superseded layer images now: one item for each home or agent (BootSpaceService::supersededLayers()).
     * The rule is evaluated live every time it is called, so a loop device or a mount that appeared
     * after the last measurement is seen. Walks the persistence folders: background job only.
     * @return array<int,array<string,mixed>>
     */
    public static function layerItems(array $seams = []): array
    {
        $ctx = self::layerCtx($seams);
        if ($ctx === null) return [];
        $size = $seams['du'] ?? static function (string $f): int { return HistoryStoreService::allocated($f); };
        return BootSpaceService::supersededLayers($ctx['layer_dirs'], $ctx['manifest_entities'], $size, $ctx);
    }

    /**
     * Archive or remove the superseded layer images. Same steps and safety rules as process(): the copy
     * is checked (size and SHA-256), listed in the manifest, and only then does the original go. A file
     * that a loop device, a mount or a process uses is never touched: the rule is run again right before
     * each original is removed. A restore of this kind is not offered (the plugin cannot use an old image).
     * Seams: those of layerCtx(), plus now, copy, free, progress, mounted, host, date.
     * @return array{status:string,message:string,done:int,files:int,bytes:int,skipped:array<int,array{id:string,reason:string}>,manifest:string,dest:string}
     */
    public static function processLayers(string $mode, string $folder = '', array $seams = []): array
    {
        $res = ['status' => 'error', 'message' => '', 'done' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => [], 'manifest' => '', 'dest' => ''];
        if (!in_array($mode, ['archive', 'remove'], true)) { $res['message'] = 'The mode must be archive or remove.'; return $res; }
        $items = self::layerItems($seams);
        if (!$items) {
            $res['status'] = 'ok';
            $res['message'] = 'Nothing to ' . ($mode === 'archive' ? 'archive' : 'remove') . '.';
            return $res;
        }
        $ctx = self::layerCtx($seams);
        $dirs = (array)($ctx['layer_dirs'] ?? []);

        $base = ''; $manifestPath = ''; $entries = [];
        if ($mode === 'archive') {
            $chk = self::checkTarget($folder, $seams);
            if ($chk['error'] !== null) { $res['message'] = (string)$chk['error']; return $res; }
            $base = self::archiveBase($chk['folder'], $seams);
            $total = 0;
            foreach ($items as $it) $total += (int)$it['bytes'];
            $free = isset($seams['free']) ? (int)($seams['free'])($chk['folder']) : (int)@disk_free_space($chk['folder']);
            if ($free < $total + self::ROOM_MARGIN) {
                $res['message'] = 'The target has too little room. It needs ' . BootSpaceService::human($total + self::ROOM_MARGIN) . ' free.';
                return $res;
            }
            if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
                $res['message'] = 'The archive folder could not be made.';
                return $res;
            }
            $manifestPath = $base . '/MANIFEST-' . self::KIND_LAYERS . '-' . self::LAYER_USER . '-' . date('His', (int)($seams['now'] ?? time())) . '.json';
            $res['dest'] = $base;
        }

        $copier = $seams['copy'] ?? [HistoryMoveService::class, 'copyFile'];
        $count = count($items); $i = 0;
        foreach ($items as $it) {
            $i++;
            $id = substr((string)$it['key'], strlen('superseded:'));
            if (isset($seams['progress'])) ($seams['progress'])(['done' => $i - 1, 'total' => $count, 'id' => $id]);
            $files = array_values(array_map('strval', (array)($it['files'] ?? [])));
            $made = [];
            if ($mode === 'archive') {
                $why = null;
                foreach ($files as $f) {
                    $rel = $id . '/' . basename($f);
                    $why = self::copyChecked($rel, $f, $base . '/' . self::LAYER_USER . '/' . self::KIND_LAYERS . '/' . $rel, $copier, $made);
                    if ($why !== null) break;
                }
                if ($why !== null) {
                    foreach ($made as $m) { if ($m['fresh']) @unlink($m['dst']); }
                    $res['skipped'][] = ['id' => $id, 'reason' => $why];
                    continue;
                }
                foreach ($made as $m) {
                    $entries[] = ['file' => $m['rel'], 'item' => $id, 'size' => $m['size'], 'sha256' => $m['sha'],
                                  'mtime' => $m['mtime'], 'archived_at' => time()];
                }
                self::writeManifest($manifestPath, self::LAYER_USER, self::KIND_LAYERS, $entries);
                $res['manifest'] = $manifestPath;
            }

            // Find the images again with the live rule. An image that a loop device, a mount or a process
            // uses now, or that is no longer superseded, stays. Then remove what is left on the list.
            $still = [];
            foreach (self::layerItems($seams) as $again) {
                if (($again['key'] ?? '') === $it['key']) { foreach ((array)$again['files'] as $f) $still[(string)$f] = true; }
            }
            $byRel = [];
            foreach ($made as $m) $byRel[$m['rel']] = $m;
            $removed = 0; $kept = 0; $bytes = 0;
            foreach ($files as $f) {
                $dir = dirname($f);
                $ok = isset($still[$f]) && in_array($dir, $dirs, true) && !is_link($f) && is_file($f);
                if ($ok && $mode === 'archive') {
                    clearstatcache(true, $f);
                    $m = $byRel[$id . '/' . basename($f)] ?? null;
                    $st = @stat($f);
                    if ($m === null || $st === false || (int)$st['size'] !== $m['size'] || (int)$st['mtime'] !== $m['mtime']) $ok = false;
                }
                if (!$ok) { $kept++; continue; }
                $b = HistoryStoreService::allocated($f);
                if (@unlink($f)) { $removed++; $bytes += $b; } else { $kept++; }
            }
            $res['files'] += $removed;
            $res['bytes'] += $bytes;
            if ($removed > 0 && $kept === 0) {
                $res['done']++;
            } elseif ($removed > 0) {
                $res['done']++;
                $res['skipped'][] = ['id' => $id, 'reason' => $kept . ' file(s) stayed because a mount or a process uses them.' . ($mode === 'archive' ? ' The copies stay in the archive.' : '')];
            } else {
                $res['skipped'][] = ['id' => $id, 'reason' => 'A mount or a process uses the images now, or they are no longer replaced.' . ($mode === 'archive' ? ' The copies stay in the archive.' : '')];
            }
        }
        if (isset($seams['progress'])) ($seams['progress'])(['done' => $count, 'total' => $count, 'id' => '']);
        $res['status'] = 'ok';
        $verb = $mode === 'archive' ? 'Archived' : 'Removed';
        $res['message'] = $verb . ' ' . $res['files'] . ' layer image(s) (' . BootSpaceService::human($res['bytes']) . ').'
            . ($res['skipped'] ? ' ' . count($res['skipped']) . ' item(s) stayed in part or whole.' : '');
        return $res;
    }

    // ---- restore (R8, #755) ------------------------------------------------------------------------

    /** The name of a manifest file: MANIFEST-<kind>-<user>-<HHMMSS>.json */
    public const MANIFEST_RE = '/^MANIFEST-(quarantine|opencode_orphans|codex_sessions|agent_sessions)-([A-Za-z0-9_][A-Za-z0-9._-]{0,127})-(\d{6})\.json$/D';
    /** The plan shows at most this many files in the dialog. The counts cover all files. */
    public const PLAN_ROWS = 300;
    /** What a person may choose for a file that is different in the home. */
    public const POLICIES = ['skip', 'replace'];

    /** @return array{kind:string,user:string,time:string}|null */
    public static function parseManifestName(string $name): ?array
    {
        if (preg_match(self::MANIFEST_RE, $name, $m) !== 1) return null;
        return ['kind' => $m[1], 'user' => $m[2], 'time' => $m[3]];
    }

    /**
     * The manifests of the archives in a checked folder (checkTarget() gave $realFolder). It reads the
     * two fixed levels <archive folder>/<host>/<date>/ with one glob. It opens no manifest and walks no tree.
     * @return array<int,array<string,mixed>> newest first
     */
    public static function listManifests(string $realFolder, int $limit = 200): array
    {
        $out = [];
        $root = rtrim($realFolder, '/') . '/' . self::ARCHIVE_FOLDER;
        if (!is_dir($root) || is_link($root)) return $out;
        foreach (glob($root . '/*/*/MANIFEST-*.json', GLOB_NOSORT) ?: [] as $p) {
            if (is_link($p) || !is_file($p)) continue;
            $parsed = self::parseManifestName(basename($p));
            $dir = dirname($p);
            if ($parsed === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', basename($dir)) !== 1) continue;
            $out[] = ['path' => $p, 'name' => basename($p), 'host' => basename(dirname($dir)), 'date' => basename($dir),
                      'kind' => $parsed['kind'], 'user' => $parsed['user'], 'time' => $parsed['time'],
                      'size' => (int)@filesize($p), 'mtime' => (int)@filemtime($p)];
        }
        usort($out, static function ($a, $b) { return ($b['mtime'] <=> $a['mtime']) ?: strcmp($b['path'], $a['path']); });
        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Check the path of a manifest that a person picked. The path must have the shape
     * <folder>/aicliagents-archive/<host>/<date>/MANIFEST-<kind>-<user>-<time>.json, the folder must pass
     * checkTarget(), and no part of the path may be a link. Seams: mounted.
     * @return array{error:?string,path:string,dir:string,kind:string,user:string}
     */
    public static function checkManifest(string $path, array $seams = []): array
    {
        $bad = static function (string $m): array { return ['error' => $m, 'path' => '', 'dir' => '', 'kind' => '', 'user' => '']; };
        if (preg_match('#^(/.+)/' . preg_quote(self::ARCHIVE_FOLDER, '#') . '/([A-Za-z0-9._-]+)/(\d{4}-\d{2}-\d{2})/(MANIFEST-[^/]+\.json)$#D', $path, $m) !== 1) {
            return $bad('This is not the manifest of an archive.');
        }
        $parsed = self::parseManifestName($m[4]);
        if ($parsed === null) return $bad('This is not the manifest of an archive.');
        $chk = self::checkTarget($m[1], $seams);
        if ($chk['error'] !== null) return $bad((string)$chk['error']);
        $canon = $chk['folder'] . '/' . self::ARCHIVE_FOLDER . '/' . $m[2] . '/' . $m[3] . '/' . $m[4];
        if (is_link($canon) || !is_file($canon)) return $bad('The manifest file was not found.');
        if (realpath($canon) !== $canon) return $bad('The archive path leads out of the folder.');
        return ['error' => null, 'path' => $canon, 'dir' => dirname($canon), 'kind' => $parsed['kind'], 'user' => $parsed['user']];
    }

    /**
     * Read a manifest. The kind and the user in the file must match the name of the file.
     * @return array{error:?string,kind:string,user:string,files:array<int,array{file:string,size:int,sha256:string,mtime:int}>}
     */
    public static function loadManifest(string $path): array
    {
        $out = ['error' => null, 'kind' => '', 'user' => '', 'files' => []];
        $parsed = self::parseManifestName(basename($path));
        $raw = @file_get_contents($path);
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        if ($parsed === null || !is_array($doc) || ($doc['plugin'] ?? '') !== 'unraid-aicliagents'
            || ($doc['kind'] ?? '') !== $parsed['kind'] || ($doc['user'] ?? '') !== $parsed['user'] || !is_array($doc['files'] ?? null)) {
            $out['error'] = 'The manifest cannot be read or does not match its name.';
            return $out;
        }
        $out['kind'] = $parsed['kind']; $out['user'] = $parsed['user'];
        $seen = [];
        foreach ($doc['files'] as $e) {
            if (!is_array($e) || !is_string($e['file'] ?? null) || !is_string($e['sha256'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/D', $e['sha256']) !== 1 || !is_int($e['size'] ?? null) || $e['size'] < 0) continue;
            if (isset($seen[$e['file']])) continue;   // one row for each file
            $seen[$e['file']] = true;
            $out['files'][] = ['file' => $e['file'], 'size' => $e['size'], 'sha256' => $e['sha256'], 'mtime' => (int)($e['mtime'] ?? 0)];
        }
        return $out;
    }

    /** Why a home-relative path of a manifest is refused, or null. The path must lie inside the folder of its kind. */
    public static function restorePathReason(string $kind, string $rel): ?string
    {
        if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false) return 'The path is not safe.';
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') return 'The path is not safe.';
        }
        if ($kind === self::KIND_AGENT_SESSIONS) {
            // The path must be a session file of one of the agents of this kind (the rules of HistoryStoreService).
            foreach (self::sessionAgents() as $agentId) {
                if (HistoryStoreService::unitKey($agentId, $rel) !== null) return null;
            }
            return 'The path lies outside the folders this archive came from.';
        }
        if ($kind === self::KIND_LAYERS) return 'This kind of archive cannot be restored.';
        $root = self::describe($kind)['root'] . '/';
        if (strncmp($rel, $root, strlen($root)) !== 0) return 'The path lies outside the folder this archive came from.';
        if ($kind === self::KIND_OPENCODE && preg_match('#^\.local/share/aicli-opencode/ws-[0-9a-f]{16}/#', $rel) !== 1) {
            return 'The path lies outside the folder this archive came from.';
        }
        return null;
    }

    /** True when $target (a path that may not exist yet) lies inside $realHome, and no link leads out. */
    private static function insideHome(string $target, string $realHome): bool
    {
        $d = dirname($target);
        while ($d !== '' && $d !== '/' && !file_exists($d) && !is_link($d)) $d = dirname($d);
        $r = @realpath($d);
        return $r !== false && ($r === $realHome || strpos($r . '/', $realHome . '/') === 0);
    }

    /**
     * Compare an archive with the home, and change nothing. Each file of the manifest gets a state:
     *   new        not in the home; a restore copies it;
     *   same       in the home with the same content; a restore does nothing;
     *   different  in the home with other content; a restore copies it only when the person chose "replace";
     *   refused    the path is not safe, lies outside the folder of its kind, or a link or folder is in the way;
     *   damaged    the archive copy is missing, or its size or SHA-256 differs from the manifest.
     * The archive copy is read and hashed here, so the plan is slow on a big archive: it runs in the
     * background job, never in a web request.
     *
     * @return array<string,mixed> status, kind, user, counts, bytes_new, bytes_different, rows (at most PLAN_ROWS), total
     */
    public static function restorePlan(string $homeDir, string $manifestPath, array $seams = []): array
    {
        $res = ['status' => 'error', 'message' => '', 'kind' => '', 'user' => '', 'counts' => ['new' => 0, 'same' => 0, 'different' => 0, 'refused' => 0, 'damaged' => 0],
                'bytes_new' => 0, 'bytes_different' => 0, 'rows' => [], 'total' => 0, 'manifest' => ''];
        $chk = self::checkManifest($manifestPath, $seams);
        if ($chk['error'] !== null) { $res['message'] = (string)$chk['error']; return $res; }
        $m = self::loadManifest($chk['path']);
        if ($m['error'] !== null) { $res['message'] = (string)$m['error']; return $res; }
        $home = rtrim($homeDir, '/');
        $realHome = realpath($home);
        if ($realHome === false || is_link($home) || !is_dir($home)) { $res['message'] = 'The home of this archive was not found.'; return $res; }
        $res['kind'] = $m['kind']; $res['user'] = $m['user']; $res['manifest'] = $chk['path'];
        $archRoot = $chk['dir'] . '/' . $m['user'] . '/' . $m['kind'];
        $rows = [];
        foreach ($m['files'] as $e) {
            $rel = $e['file'];
            $row = ['file' => $rel, 'size' => $e['size'], 'state' => '', 'reason' => '', 'archived_mtime' => $e['mtime'], 'home_mtime' => 0, 'home_newer' => false];
            $target = $home . '/' . $rel;
            $src = $archRoot . '/' . $rel;
            $why = self::restorePathReason($m['kind'], $rel);
            if ($why === null && (!self::insideHome($target, $realHome) || is_link($target))) $why = 'A link leads out of the home or is in the way.';
            if ($why === null && file_exists($target) && !is_file($target)) $why = 'A folder is at this place.';
            if ($why !== null) {
                $row['state'] = 'refused'; $row['reason'] = $why;
            } elseif (is_link($src) || !is_file($src)) {
                $row['state'] = 'damaged'; $row['reason'] = 'The archive copy is missing.';
            } elseif ((int)@filesize($src) !== $e['size'] || @hash_file('sha256', $src) !== $e['sha256']) {
                $row['state'] = 'damaged'; $row['reason'] = 'The archive copy does not match the manifest.';
            } elseif (!file_exists($target)) {
                $row['state'] = 'new';
                $res['bytes_new'] += $e['size'];
            } else {
                $row['home_mtime'] = (int)@filemtime($target);
                if ((int)@filesize($target) === $e['size'] && @hash_file('sha256', $target) === $e['sha256']) {
                    $row['state'] = 'same';
                } else {
                    $row['state'] = 'different';
                    $row['home_newer'] = $row['home_mtime'] > $e['mtime'];
                    $res['bytes_different'] += $e['size'];
                }
            }
            $res['counts'][$row['state']]++;
            $rows[] = $row;
        }
        $res['total'] = count($rows);
        // The dialog lists the files that need a look first: different, then refused and damaged, then new, then same.
        $rank = ['different' => 0, 'refused' => 1, 'damaged' => 1, 'new' => 2, 'same' => 3];
        usort($rows, static function ($a, $b) use ($rank) { return ($rank[$a['state']] <=> $rank[$b['state']]) ?: strcmp($a['file'], $b['file']); });
        $res['rows'] = array_slice($rows, 0, self::PLAN_ROWS);
        $res['status'] = 'ok';
        return $res;
    }

    /**
     * Put the files of an archive back into the home. Run only after the person saw restorePlan() and
     * chose a policy for the files that are different: 'skip' keeps the file in the home (the default),
     * 'replace' copies the archived file over it. The plan is made again here, so a file that changed
     * since the dialog is judged by its state now. A new file never overwrites anything. A different
     * file is replaced only on 'replace', and never while a process holds it open.
     * Each file is copied to a temporary name, checked (size and SHA-256 from the manifest), and then
     * renamed into place. The archive is never changed.
     *
     * $seams: copy (fn(src,dst):bool), free (fn(dir):int), open (fn(home):string[]), progress (fn(array)), mounted.
     * @return array<string,mixed>
     */
    public static function restore(string $homeDir, string $manifestPath, string $policy, array $seams = []): array
    {
        $res = ['status' => 'error', 'message' => '', 'kind' => '', 'user' => '', 'done' => 0, 'files' => 0, 'bytes' => 0,
                'replaced' => 0, 'unchanged' => 0, 'kept' => 0, 'skipped' => []];
        if (!in_array($policy, self::POLICIES, true)) { $res['message'] = 'The choice for different files must be skip or replace.'; return $res; }
        $chk = self::checkManifest($manifestPath, $seams);
        if ($chk['error'] !== null) { $res['message'] = (string)$chk['error']; return $res; }
        $m = self::loadManifest($chk['path']);
        if ($m['error'] !== null) { $res['message'] = (string)$m['error']; return $res; }
        $home = rtrim($homeDir, '/');
        if (realpath($home) === false || is_link($home) || !is_dir($home)) { $res['message'] = 'The home of this archive was not found.'; return $res; }
        $res['kind'] = $m['kind']; $res['user'] = $m['user'];
        $archRoot = $chk['dir'] . '/' . $m['user'] . '/' . $m['kind'];

        // Judge every file now, with the same rules as the plan. The state is the state at this moment,
        // not the state the dialog showed.
        $need = 0;
        $copy = [];
        foreach ($m['files'] as $e) {
            $st = self::stateNow($home, $archRoot, $m['kind'], $e);
            if ($st['state'] === 'same') { $res['unchanged']++; continue; }
            if ($st['state'] === 'new' || ($st['state'] === 'different' && $policy === 'replace')) {
                $copy[] = $e; $need += $e['size'];
                continue;
            }
            if ($st['state'] === 'different') { $res['kept']++; continue; }
            $res['skipped'][] = ['id' => $e['file'], 'reason' => $st['reason']];
        }
        if (!$copy) {
            $res['status'] = 'ok';
            $res['message'] = 'Nothing to restore. ' . self::restoreSummary($res);
            return $res;
        }
        $free = isset($seams['free']) ? (int)($seams['free'])($home) : (int)@disk_free_space($home);
        if ($free < $need + self::ROOM_MARGIN) {
            $res['message'] = 'The boot disk has too little room. The restore needs ' . BootSpaceService::human($need + self::ROOM_MARGIN) . ' free.';
            return $res;
        }
        // R8 (#724): a large write to the boot disk must not push it over 85 %.
        $refused = BootSpaceService::writeGuard($home, $need, 'the restore from an archive', $seams);
        if ($refused !== null) { $res['message'] = $refused; return $res; }
        $open = null;
        $copier = $seams['copy'] ?? [HistoryMoveService::class, 'copyFile'];
        $owner = @stat($home);
        $count = count($copy); $i = 0;
        foreach ($copy as $e) {
            $i++;
            if (isset($seams['progress'])) ($seams['progress'])(['done' => $i - 1, 'total' => $count, 'id' => $e['file']]);
            $target = $home . '/' . $e['file'];
            $src = $archRoot . '/' . $e['file'];
            // A cheap check right before the copy: a file that was new must still be absent, and a file
            // that was different must still be there.
            $isDiff = file_exists($target);
            if ($isDiff && $policy !== 'replace') {
                $res['skipped'][] = ['id' => $e['file'], 'reason' => 'A file appeared at this place.'];
                continue;
            }
            if ($isDiff) {
                if ($open === null) {
                    $open = [];
                    $paths = isset($seams['open']) ? (array)($seams['open'])($home) : HistoryStoreService::procOpenFiles($home);
                    foreach ($paths as $p) $open[(string)$p] = true;
                }
                if (isset($open[$target])) { $res['skipped'][] = ['id' => $e['file'], 'reason' => 'A process uses the file in the home.']; continue; }
            }
            $dir = dirname($target);
            if (!is_dir($dir)) {
                self::mkdirOwned($dir, $home, $owner);
                if (!is_dir($dir)) { $res['skipped'][] = ['id' => $e['file'], 'reason' => 'The folder could not be made.']; continue; }
            }
            $part = $target . '.aicli-restore.part';
            @unlink($part);
            $ok = $copier($src, $part);
            if (!$ok || !is_file($part) || (int)@filesize($part) !== $e['size'] || @hash_file('sha256', $part) !== $e['sha256']) {
                @unlink($part);
                $res['skipped'][] = ['id' => $e['file'], 'reason' => 'The copy did not match the manifest, so nothing changed.'];
                continue;
            }
            $sst = @stat($src);
            if ($sst !== false) @chmod($part, (int)$sst['mode'] & 07777);
            if ($e['mtime'] > 0) @touch($part, $e['mtime']);
            if ($owner !== false) { @chown($part, (int)$owner['uid']); @chgrp($part, (int)$owner['gid']); }
            if (!$isDiff && file_exists($target)) { @unlink($part); $res['skipped'][] = ['id' => $e['file'], 'reason' => 'A file appeared at this place.']; continue; }
            if (!@rename($part, $target)) { @unlink($part); $res['skipped'][] = ['id' => $e['file'], 'reason' => 'The copy could not be finished.']; continue; }
            $res['done']++; $res['files']++; $res['bytes'] += $e['size'];
            if ($isDiff) $res['replaced']++;
        }
        if (isset($seams['progress'])) ($seams['progress'])(['done' => $count, 'total' => $count, 'id' => '']);
        $res['status'] = 'ok';
        $res['message'] = 'Restored ' . $res['done'] . ' file(s) (' . BootSpaceService::human($res['bytes']) . '). ' . self::restoreSummary($res);
        return $res;
    }

    private static function restoreSummary(array $res): string
    {
        $bits = [];
        if ($res['replaced'] > 0) $bits[] = $res['replaced'] . ' replaced';
        if ($res['unchanged'] > 0) $bits[] = $res['unchanged'] . ' already the same';
        if ($res['kept'] > 0) $bits[] = $res['kept'] . ' different file(s) kept in the home';
        if ($res['skipped']) $bits[] = count($res['skipped']) . ' stayed';
        return $bits ? ucfirst(implode(', ', $bits)) . '.' : '';
    }

    /**
     * The state of one manifest entry now (the same rules as restorePlan, for one file).
     * @param array{file:string,size:int,sha256:string,mtime:int} $e
     * @return array{state:string,reason:string}
     */
    private static function stateNow(string $home, string $archRoot, string $kind, array $e): array
    {
        $realHome = realpath($home);
        $target = $home . '/' . $e['file'];
        $src = $archRoot . '/' . $e['file'];
        $why = self::restorePathReason($kind, $e['file']);
        if ($why === null && ($realHome === false || !self::insideHome($target, $realHome) || is_link($target))) $why = 'A link leads out of the home or is in the way.';
        if ($why === null && file_exists($target) && !is_file($target)) $why = 'A folder is at this place.';
        if ($why !== null) return ['state' => 'refused', 'reason' => $why];
        if (is_link($src) || !is_file($src)) return ['state' => 'damaged', 'reason' => 'The archive copy is missing.'];
        if ((int)@filesize($src) !== $e['size'] || @hash_file('sha256', $src) !== $e['sha256']) return ['state' => 'damaged', 'reason' => 'The archive copy does not match the manifest.'];
        if (!file_exists($target)) return ['state' => 'new', 'reason' => ''];
        if ((int)@filesize($target) === $e['size'] && @hash_file('sha256', $target) === $e['sha256']) return ['state' => 'same', 'reason' => ''];
        return ['state' => 'different', 'reason' => 'A different file is in the home.'];
    }

    /** Make a folder (and its parents) inside the home, owned like the home. */
    private static function mkdirOwned(string $dir, string $home, $owner): void
    {
        $todo = [];
        $d = $dir;
        while (strlen($d) > strlen($home) && !is_dir($d)) { $todo[] = $d; $d = dirname($d); }
        foreach (array_reverse($todo) as $t) {
            if (!@mkdir($t, 0755) && !is_dir($t)) return;
            if ($owner !== false) { @chown($t, (int)$owner['uid']); @chgrp($t, (int)$owner['gid']); }
        }
    }

    /**
     * Last checks on an item before it is copied or removed. Returns a reason, or null when it may go.
     * With $recheckOpen the open files are read again (the item must not be in use right before it goes).
     * @param array{id:string,rels:string[],bytes:int,mtime:int} $u
     */
    private static function unitGuard(string $home, string $realHome, array $u, array $seams, bool $recheckOpen): ?string
    {
        $open = [];
        if ($recheckOpen) {
            $paths = isset($seams['open']) ? (array)($seams['open'])($home) : HistoryStoreService::procOpenFiles($home);
            foreach ($paths as $p) $open[(string)$p] = true;
        }
        foreach ($u['rels'] as $rel) {
            if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) return 'The path is not safe.';
            $abs = $home . '/' . $rel;
            if (is_link($abs) || !is_file($abs)) return 'A file is gone or is a link.';
            $dirReal = realpath(dirname($abs));
            if ($dirReal === false || strpos($dirReal . '/', $realHome . '/') !== 0) return 'A file lies outside the home.';
            if (isset($open[$abs])) return 'A process uses it.';
        }
        return null;
    }

    /**
     * Copy one file, check size and SHA-256. Returns a reason on failure. A file that is already in
     * the archive with the same content counts as copied (fresh = false). A different file at the
     * same place is never overwritten.
     * @param array<int,array{rel:string,dst:string,size:int,sha:string,mtime:int,fresh:bool}> $made
     */
    private static function copyChecked(string $rel, string $src, string $dst, callable $copier, array &$made): ?string
    {
        $st = @stat($src);
        $sha = @hash_file('sha256', $src);
        if ($st === false || $sha === false) return 'A file cannot be read.';
        $row = ['rel' => $rel, 'dst' => $dst, 'size' => (int)$st['size'], 'sha' => $sha, 'mtime' => (int)$st['mtime'], 'fresh' => true];
        if (file_exists($dst)) {
            if (@hash_file('sha256', $dst) !== $sha) return 'A different file is already in the archive at this place.';
            $row['fresh'] = false;
            $made[] = $row;
            return null;
        }
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return 'The archive folder could not be made.';
        $part = $dst . '.part';
        $ok = $copier($src, $part);
        if (!$ok || !is_file($part) || (int)@filesize($part) !== (int)$st['size'] || @hash_file('sha256', $part) !== $sha) {
            @unlink($part);
            return 'The copy did not match the original, so the original stays.';
        }
        @chmod($part, (int)$st['mode'] & 07777);
        @touch($part, (int)$st['mtime']);
        if (!@rename($part, $dst)) { @unlink($part); return 'The copy could not be finished.'; }
        $made[] = $row;
        return null;
    }
    /** @param array<int,array<string,mixed>> $entries */
    private static function writeManifest(string $path, string $user, string $kind, array $entries): void
    {
        $doc = ['plugin' => 'unraid-aicliagents', 'kind' => $kind, 'user' => $user,
                'host' => gethostname() ?: '', 'written_at' => time(), 'files' => $entries];
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $path);
    }

    /** Remove the folders that the removal emptied, up to (not including) the root of the kind. */
    private static function pruneEmptyDirs(string $home, string $kind, array $rels): void
    {
        $dirs = [];
        foreach (self::roots($kind) as $r) {
            $root = $home . '/' . $r;
            foreach ($rels as $rel) {
                $d = dirname($home . '/' . $rel);
                while (strlen($d) > strlen($root) && strpos($d, $root . '/') === 0) { $dirs[$d] = strlen($d); $d = dirname($d); }
            }
        }
        arsort($dirs);
        foreach (array_keys($dirs) as $d) {
            if (is_dir($d) && !is_link($d)) @rmdir($d);   // rmdir removes an empty folder only
        }
    }
}
