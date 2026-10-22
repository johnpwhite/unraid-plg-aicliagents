<?php
/**
 * <module_context>
 *     <name>NotebookService</name>
 *     <description>The Notebook (artefacts) store, docs/specs/ARTEFACTS.md and
 *     docs/specs/ARTEFACTS_CONTRACT.md. A notebook is a folder in the project root
 *     (setting notebook_folder, default `notebook`) with the areas inbox, working, notes and
 *     archive. One artefact is one plain file; its version is the first 12 hex characters of the
 *     sha256 of the file bytes. This class creates, reads, patches, moves, archives, restores,
 *     comments on, searches, exports, imports and shares artefacts, keeps connection-card
 *     secrets in the vault (SecretService), reads git history, and publishes every change on
 *     the `notebook` event channel. Both surfaces use it: the browser through NotebookHandler and
 *     the agent tools and CLI.</description>
 *     <dependencies>NotebookFormat, NotebookTemplates, NotebookSecretGuard, AtomicWriteService,
 *     PoolPathService (project path to pool path), SecretService (vault), ConfigService (workspaces,
 *     settings), EventBus (publish), ActivityService (Tier-3 purge item), VoiceService (read aloud).</dependencies>
 *     <constraints>Every public method returns an array and returns ['error'=>string] (plus keys such
 *     as currentVersion or needsConfirm) on failure; none throws. A secret VALUE never reaches a
 *     file, an index, the event ledger, the tray, a log line or a result (except secretReveal's
 *     own `value`, which is returned once to a person and never logged). The tools never delete:
 *     archive moves a file to archive/; only purgeExecute (after a person approves in the tray)
 *     removes one. Writes go through AtomicWriteService under a non-blocking flock polled to a
 *     deadline; the file hash is re-read right before the write and a mismatch refuses the write.
 *     The project path is resolved to its pool path (never bulk I/O through /mnt/user). Git runs
 *     with `git -C`, GIT_OPTIONAL_LOCKS=0 and a 3 second deadline. Test seams: $rootOverride,
 *     $stateDirOverride, $vaultStub, $eventSink, $activitySink, $speakSink. Under PHPUnit (AICLI_TEST_TMP defined)
 *     a root or lock folder outside the temp area is refused, the real vault is refused and nothing speaks, so a
 *     test can never touch a real notebook (docs/specs/NOTEBOOK_TESTING.md).</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/NotebookFormat.php';
require_once __DIR__ . '/NotebookTemplates.php';
require_once __DIR__ . '/NotebookSecretGuard.php';
require_once __DIR__ . '/AtomicWriteService.php';
require_once __DIR__ . '/PoolPathService.php';
require_once __DIR__ . '/SecretService.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/PerfTrace.php';

class NotebookService {

    // ----- Test seams (all null in production) -----
    /** @var string|null Point every context at this notebook root. */
    public static ?string $rootOverride = null;
    /** @var string|null Replace /tmp/unraid-aicliagents/notebook-state. */
    public static ?string $stateDirOverride = null;
    /** Test seam: a fixed list of workspace records for wikiProjects(). Null reads the real registry. */
    public static ?array $workspacesOverride = null;
    /** @var array|null In-memory vault: notebookId => [KEY => value]. When set, SecretService is not touched. */
    public static ?array $vaultStub = null;
    /** @var callable|null Receives the event payload instead of EventBus::publish(). */
    public static $eventSink = null;
    /** Test seam: receives the spoken line instead of VoiceService. Under PHPUnit nothing speaks without it. */
    public static $speakSink = null;
    /** @var callable|null Receives [opId, tool, short question, params, caller, full statement] instead of ActivityService::propose(). */
    public static $activitySink = null;

    // ----- Limits (contract section 6) -----
    public const MAX_FILE_BYTES    = 1048576;   // 1 MB per file (docs/specs/ARTEFACTS_CONTRACT.md)
    /** The file size limit in plain words, for messages ("1 MB"). */
    public static function limitLabel(): string { return self::sizeLabel(self::MAX_FILE_BYTES); }
    /** A byte count in plain words: whole megabytes as "N MB", anything else as "N KB". */
    public static function sizeLabel(int $bytes): string {
        if ($bytes % 1048576 === 0) return intdiv($bytes, 1048576) . ' MB';
        return intdiv($bytes, 1024) . ' KB';
    }
    public const MAX_ARTEFACTS     = 2000;
    public const MAX_ASSET_BYTES   = 5242880;
    public const MAX_ASSETS_TOTAL  = 104857600;
    public const MAX_READ_BYTES    = 1048576;
    public const RATE_PER_MINUTE   = 60;
    public const LOCK_WAIT_SECONDS = 5;
    /** The global notebook folder on flash (the flash root under test is the sandbox). */
    public static function globalRoot(): string { return SecretPaths::flashRoot() . '/notebook/global'; }
    /** The notebook state folder in RAM (the state root under test is the sandbox). */
    private static function stateDirDefault(): string { return SecretPaths::stateRoot() . '/notebook-state'; }
    /** The durable mirror of the notebook state folder on flash. */
    private static function stateDirFlash(): string { return SecretPaths::flashRoot() . '/notebook-state'; }

    /** Setting notebook_quiet_hours: HH:MM-HH:MM (may wrap midnight) or empty. */
    private const QUIET_RE = '/^(?:|([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d)$/D';
    private const JOURNAL_TITLE = 'Agent journal';
    private const JOURNAL_KEEP = 500;
    private const JOURNAL_MAX_CHARS = 500;
    private const LOCKED_MESSAGE = 'This page is locked by a person. Add a comment with your suggestion instead.';
    private const NO_PROJECT = 'This workspace has no project folder, so it has no notebook.';
    private const ASSET_EXT = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json', 'log' => 'text/plain', 'zip' => 'application/zip',
    ];

    /** @var array<string,array{0:float,1:?array}> */
    private static array $gitCache = [];

    // ----- Workspace lookup cache (docs/specs/NOTEBOOK_HANG_OBSERVABILITY.md R3, R4) -----
    /** The cache file. Under PHPUnit (AICLI_TEST_TMP) it is OFF unless setWorkspaceCachePath() names a file. */
    private static function wsCacheFile(): string { return SecretPaths::stateRoot() . '/notebook-ws-cache.json'; }
    /** An entry older than this many seconds is not used. */
    private const WS_CACHE_TTL = 20;
    private const WS_CACHE_MAX_ENTRIES = 200;
    /** Test seam: the cache file path. Null = the default file (and no cache under test). */
    private static ?string $wsCachePathOverride = null;
    /** True when the last findWorkspace() answered from the cache. contextFor() reads it for the trace. */
    private static bool $lastLookupWasCacheHit = false;
    /** Test seam: replaces the registry read. Returns the list of workspace records. @var callable|null */
    public static $registryLookup = null;

    /** Test seam: point the workspace cache at a file (null = default; under test the cache is then off). */
    public static function setWorkspaceCachePath(?string $path): void {
        self::$wsCachePathOverride = $path;
    }

    // ======================================================================
    // Context and author
    // ======================================================================

    /** @return array<string,mixed> ['root','projectPath','notebookId','scope','workspaceId','name','settings'] or ['error'] */
    public static function contextFor(string $workspaceId): array {
        try {
            $settings = self::settings();
            if ($settings['enabled'] !== '1') return ['error' => 'The Notebook is turned off in the plugin settings.', 'disabled' => true];
            $workspaceId = trim($workspaceId);
            if ($workspaceId === '') return ['error' => 'A workspace id is needed.'];
            if ($workspaceId !== 'global' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $workspaceId)) return ['error' => 'That is not a valid workspace id.'];
            $scope = $workspaceId === 'global' ? 'global' : 'notebook';
            $project = '';
            $wsName = '';
            if (self::$rootOverride !== null) {
                $root = rtrim(self::$rootOverride, '/');
                $project = dirname($root);
            } elseif (defined('AICLI_TEST_TMP') && $scope === 'global') {
                return ['error' => 'Test guard: the global notebook needs $rootOverride under test.'];
            } elseif ($scope === 'global') {
                $root = self::globalRoot();
            } else {
                $lookupT0 = PerfTrace::start();
                $rec = self::findWorkspace($workspaceId);
                PerfTrace::point('notebook.context', $lookupT0, 500, "ws=$workspaceId cache=" . (self::$lastLookupWasCacheHit ? 'hit' : 'miss'));
                if ($rec === null) return ['error' => "There is no workspace '$workspaceId'."];
                $path = rtrim((string)($rec['path'] ?? ''), '/');
                if ($path === '' || !is_dir($path)) return ['error' => self::NO_PROJECT];
                $project = PoolPathService::resolvePoolPath($path) ?? $path;
                $wsName = (string)($rec['name'] ?? '');
                $folder = $settings['folder'];
                $root = $project . '/' . $folder;
            }
            if (self::unsafeInTest($root)) return ['error' => 'Test guard: this notebook folder is outside the test temp area.'];
            if (file_exists($root) && !is_dir($root)) {
                return ['error' => "The notebook folder name clashes with an existing file: $root. Rename or move that file."];
            }
            $marker = self::readMarker($root);
            $id = $marker['id'] ?? ('nb_' . substr(sha1($root), 0, 12));
            return [
                'root' => $root, 'projectPath' => $project, 'notebookId' => $id, 'scope' => $scope,
                'workspaceId' => $workspaceId, 'name' => basename($root), 'workspaceName' => $wsName, 'settings' => $settings,
            ];
        } catch (\Throwable $e) {
            self::log('contextFor failed: ' . $e->getMessage());
            return ['error' => 'The notebook could not be located.'];
        }
    }

    /**
     * Who is acting. null = a person in the browser. A string = an agent: a workspace id, or '' to read
     * AICLI_SESSION_ID from the environment (agent tools and the CLI).
     * @return array{kind:string,agentId:string,name:string,workspace:string,workspaceId:string}
     */
    public static function author(?string $callerWorkspaceId = null): array {
        if ($callerWorkspaceId === null) {
            return ['kind' => 'human', 'agentId' => '', 'name' => 'Person', 'workspace' => '', 'workspaceId' => ''];
        }
        $sid = $callerWorkspaceId !== '' ? $callerWorkspaceId : (string)(getenv('AICLI_SESSION_ID') ?: '');
        $a = ['kind' => 'agent', 'agentId' => '', 'name' => '', 'workspace' => '', 'workspaceId' => $sid];
        if ($sid !== '') {
            $rec = self::findWorkspace($sid);
            if ($rec !== null) {
                $a['agentId'] = (string)($rec['agentId'] ?? '');
                $a['workspace'] = (string)($rec['name'] ?? '');
                $spoken = trim((string)($rec['spoken_name'] ?? ''));
                $a['name'] = $spoken !== '' ? $spoken : ($a['workspace'] !== '' ? $a['workspace'] : $a['agentId']);
            }
        }
        if ($a['name'] === '') $a['name'] = $a['agentId'] !== '' ? $a['agentId'] : 'agent';
        return $a;
    }

    /** @return array{kind:string,agentId:string,name:string,workspace:string} */
    private static function ownerOf(array $author): array {
        return [
            'kind' => ($author['kind'] ?? 'human') === 'agent' ? 'agent' : 'human',
            'agentId' => (string)($author['agentId'] ?? ''),
            'name' => (string)($author['name'] ?? ''),
            'workspace' => (string)($author['workspace'] ?? ''),
        ];
    }

    private static function byLabel(array $author): string {
        return ($author['kind'] ?? '') === 'agent' ? 'agent:' . ((string)($author['name'] ?? '') ?: 'agent') : 'human';
    }

    // ======================================================================
    // Reads
    // ======================================================================

    public static function info(array $ctx): array {
        // Off: say so in `settings`, so the page can hide the rail and the drawer.
        if (isset($ctx['error'])) return ['available' => false, 'reason' => (string)$ctx['error']] + (!empty($ctx['disabled']) ? ['settings' => ['enabled' => false]] : []);
        try {
            $exists = is_dir($ctx['root']);
            $recs = $exists ? self::scan($ctx) : [];
            $view = self::makeView($ctx, $recs);
            $counts = ['inbox' => 0, 'working' => 0, 'notes' => 0, 'archive' => 0];
            $att = ['needsYou' => 0, 'new' => 0, 'changed' => 0];
            foreach ($recs as $rec) {
                $counts[$rec['area']]++;
                $a = self::attentionFor($rec, $view);
                if ($a === 'needs-you') $att['needsYou']++;
                elseif ($a === 'new') $att['new']++;
                elseif ($a === 'changed') $att['changed']++;
            }
            return [
                'available' => true, 'root' => $ctx['root'], 'notebookId' => $ctx['notebookId'], 'name' => $ctx['name'],
                'scope' => $ctx['scope'], 'exists' => $exists, 'counts' => $counts, 'attention' => $att,
                'git' => ['repo' => (bool)($view['git']['repo'] ?? false)],
                'settings' => ['enabled' => $ctx['settings']['enabled'] === '1', 'autoOpen' => $ctx['settings']['autoOpen'] === '1', 'journal' => $ctx['settings']['journal'] === '1'],
            ];
        } catch (\Throwable $e) {
            self::log('info failed: ' . $e->getMessage());
            return ['available' => false, 'reason' => 'The notebook could not be read.'];
        }
    }

    public static function listArtefacts(array $ctx, array $filters = []): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (!is_dir($ctx['root'])) return ['artefacts' => []];
            $recs = self::scan($ctx);
            $view = self::makeView($ctx, $recs);
            $since = null;
            if (!empty($filters['changedSince'])) {
                $cs = (string)$filters['changedSince'];
                $since = ctype_digit($cs) ? gmdate('Y-m-d\TH:i:s\Z', (int)$cs) : $cs;
            }
            $q = strtolower(trim((string)($filters['q'] ?? '')));
            $out = [];
            foreach ($recs as $rec) {
                if (!empty($filters['area']) && $rec['area'] !== $filters['area']) continue;
                if (!empty($filters['type']) && $rec['type'] !== $filters['type']) continue;
                if (!empty($filters['tag']) && !in_array(strtolower((string)$filters['tag']), array_map('strtolower', $rec['meta']['tags']), true)) continue;
                if (!empty($filters['madeBy']) && !self::madeBy($rec['meta']['owner'], (string)$filters['madeBy'])) continue;
                if ($since !== null && (string)$rec['meta']['updatedAt'] < $since) continue;
                $m = self::toMeta($ctx, $rec, $view);
                if ($q !== '' && strpos(strtolower($m['title'] . ' ' . implode(' ', $m['tags']) . ' ' . $m['summary']), $q) === false) continue;
                $out[] = $m;
            }
            usort($out, static function ($a, $b) {
                if ($a['pinned'] !== $b['pinned']) return $a['pinned'] ? -1 : 1;
                return strcmp((string)$b['updatedAt'], (string)$a['updatedAt']);
            });
            return ['artefacts' => $out];
        } catch (\Throwable $e) {
            self::log('list failed: ' . $e->getMessage());
            return ['error' => 'The notebook could not be listed.'];
        }
    }

    public static function get(array $ctx, string $id): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $view = self::makeView($ctx, [$rec]);
            $full = self::toMeta($ctx, $rec, $view);
            $full['raw'] = $rec['raw'];
            $full['body'] = $rec['body'];
            $full['meta'] = $rec['meta'];
            $full['unresolvedComments'] = self::countUnresolved($ctx, $id);
            if ($rec['type'] === 'connection') {
                $present = [];
                $vault = self::vaultLoad($ctx);
                foreach ((array)($rec['meta']['secrets'] ?? []) as $field => $ref) {
                    $present[(string)$field] = isset($vault[self::vaultKey($id, (string)$field)]);
                }
                $full['secretsPresent'] = $present;
            }
            if (self::isJsonRec($rec)) {
                $d = json_decode($rec['body'], true);
                if (is_array($d)) $full['data'] = $d;
            }
            return ['artefact' => $full];
        } catch (\Throwable $e) {
            self::log('get failed: ' . $e->getMessage());
            return ['error' => 'The artefact could not be read.'];
        }
    }

    /**
     * Ask the agent of this notebook's workspace to read a note again (Forgejo #446,
     * docs/specs/NOTEBOOK_REFRESH_REQUEST.md R2/R3). One message is typed into the workspace
     * terminal through AdminService::sendInput, so the idle-prompt check, the rate limit, the
     * ledger event and the tray entry all apply. The text names the note by title, absolute
     * path and id. Nothing is queued: a busy agent answers `deferred`.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $seams optional `send` callable(workspaceId, text): array
     * @return array<string,mixed> ['delivered'=>bool,'deferred'=>bool,'reason'?,'chars'] or ['error'=>...]
     */
    public static function requestRefresh(array $ctx, string $id, string $note = '', array $seams = []): array {
        if (isset($ctx['error'])) return $ctx;
        if (($ctx['scope'] ?? '') === 'global' || (string)($ctx['workspaceId'] ?? '') === '' || $ctx['workspaceId'] === 'global') {
            return ['error' => 'The global Notebook has no agent to ask. Open the Notebook of a workspace.'];
        }
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $title = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)($rec['meta']['title'] ?? '')));
            $path = rtrim((string)$ctx['root'], '/') . '/' . ltrim((string)($rec['rel'] ?? ''), '/');
            $note = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $note));
            if (function_exists('mb_substr')) $note = mb_substr($note, 0, 300); else $note = substr($note, 0, 300);
            $text = 'Please refresh the Notebook note "' . $title . '" (file: ' . $path . ', id ' . $id . '). '
                . 'Read it again, update it so it matches the current state, and save your answer in the Notebook, not here.'
                . ($note !== '' ? ' What changed: ' . $note : '');
            $send = $seams['send'] ?? static function (string $ws, string $t): array {
                if (!class_exists('AICliAgents\Services\AdminService', false)) require_once __DIR__ . '/AdminService.php';
                return AdminService::sendInput($ws, $t, true, false);
            };
            $r = $send((string)$ctx['workspaceId'], $text);
            if (isset($r['error'])) return ['error' => (string)$r['error']];
            return [
                'delivered' => !empty($r['delivered']),
                'deferred' => !empty($r['deferred']),
                'reason' => (string)($r['reason'] ?? ''),
                'chars' => strlen($text),
                'path' => $path,
            ];
        } catch (\Throwable $e) {
            self::log('requestRefresh failed: ' . $e->getMessage());
            return ['error' => 'The refresh request could not be sent.'];
        }
    }

    public static function templates(): array {
        return ['templates' => NotebookTemplates::listAll()];
    }

    public static function comments(array $ctx, string $id): array {
        if (isset($ctx['error'])) return $ctx;
        if (self::findRecord($ctx, $id) === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
        return ['comments' => self::readComments($ctx, $id)['comments']];
    }

    public static function backlinks(array $ctx, string $id): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $target = self::findRecord($ctx, $id);
            if ($target === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $title = strtolower($target['meta']['title']);
            $links = [];
            foreach (self::scan($ctx) as $rec) {
                if ($rec['id'] === $id) continue;
                // [[a_id]], [[Title]] and [[Title|label]]: the part before the first | is the target.
                if (strpos($rec['raw'], '[[') === false || !preg_match_all('/\[\[([^\[\]\r\n]{1,200})\]\]/', $rec['raw'], $m)) continue;
                foreach ($m[1] as $rawLink) {
                    $t = strtolower(trim(explode('|', $rawLink, 2)[0]));
                    if ($t !== '' && ($t === strtolower($id) || $t === $title)) {
                        $links[] = ['id' => $rec['id'], 'title' => $rec['meta']['title']];
                        break;
                    }
                }
            }
            return ['links' => $links];
        } catch (\Throwable $e) {
            return ['error' => 'Backlinks could not be read.'];
        }
    }

    public static function search(array $ctx, string $q): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $q = trim($q);
            if ($q === '') return ['results' => []];
            if (!is_dir($ctx['root'])) return ['results' => []];
            $needle = strtolower($q);
            $words = array_values(array_filter(preg_split('/\s+/', $needle) ?: []));
            $results = [];
            foreach (self::scan($ctx) as $rec) {
                $title = strtolower($rec['meta']['title']);
                $tags = strtolower(implode(' ', $rec['meta']['tags']));
                $body = strtolower($rec['body']);
                $cm = '';
                $cf = self::commentsPath($ctx, $rec['id']);
                if (is_file($cf)) {
                    foreach ((array)(json_decode((string)@file_get_contents($cf), true)['comments'] ?? []) as $c) {
                        $cm .= ' ' . strtolower((string)($c['text'] ?? ''));
                        foreach ((array)($c['replies'] ?? []) as $r) $cm .= ' ' . strtolower((string)($r['text'] ?? ''));
                    }
                }
                $score = 0;
                if (strpos($title, $needle) !== false) $score += 20;
                if (strpos($tags, $needle) !== false) $score += 8;
                foreach ($words as $w) {
                    if (strpos($title, $w) !== false) $score += 10;
                    if (strpos($tags, $w) !== false) $score += 6;
                    $score += min(5, substr_count($body, $w));
                    $score += min(2, substr_count($cm, $w)) * 2;
                }
                if ($score <= 0) continue;
                if ($rec['area'] === 'archive') $score = (int)max(1, floor($score / 2));
                $results[] = [
                    'id' => $rec['id'], 'title' => $rec['meta']['title'], 'type' => $rec['type'], 'area' => $rec['area'],
                    'snippet' => self::snippet($rec['body'], $words), 'score' => $score,
                ];
            }
            usort($results, static fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']));
            return ['results' => array_slice($results, 0, 50)];
        } catch (\Throwable $e) {
            return ['error' => 'The search failed.'];
        }
    }

    public static function history(array $ctx, string $id): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $none = ['repo' => false, 'committed' => null, 'commits' => [], 'diff' => '', 'checkoutCommand' => '',
                'reason' => 'This notebook is not in a git repository, so there is no history.'];
            $g = self::gitInfo($ctx);
            if (!$g['repo']) return $none;
            $log = self::git($ctx['root'], ['log', '-n', '10', '--format=%H%x1f%an%x1f%aI%x1f%s', '--', $rec['rel']]);
            $commits = [];
            if ($log !== null && $log[0] === 0) {
                foreach (explode("\n", trim($log[1])) as $line) {
                    $p = explode("\x1f", $line);
                    if (count($p) >= 4) $commits[] = ['hash' => substr($p[0], 0, 10), 'author' => $p[1], 'date' => $p[2], 'subject' => $p[3]];
                }
            }
            $diff = '';
            if ($commits !== []) {
                $d = self::git($ctx['root'], ['diff', '--no-color', '--no-ext-diff', 'HEAD', '--', $rec['rel']], 3000, 200000);
                if ($d !== null && $d[0] === 0) $diff = $d[1];
            }
            $cmd = $commits !== [] ? 'git checkout ' . $commits[0]['hash'] . ' -- ' . $ctx['name'] . '/' . $rec['rel'] : '';
            return [
                'repo' => true, 'committed' => !isset($g['dirty'][$rec['rel']]),
                'commits' => $commits, 'diff' => $diff, 'checkoutCommand' => $cmd,
            ];
        } catch (\Throwable $e) {
            return ['error' => 'The history could not be read.'];
        }
    }

    // ======================================================================
    // Create, save, patch
    // ======================================================================

    public static function create(array $ctx, array $p, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if ($e = self::rateCheck($ctx, $author)) return ['error' => $e];
            $tpl = null;
            if (!empty($p['template'])) {
                $tpl = NotebookTemplates::get((string)$p['template']);
                if ($tpl === null) return ['error' => "There is no template '" . (string)$p['template'] . "'."];
            }
            $runOfId = (string)($p['runOf'] ?? '');
            $source = null;
            if ($runOfId !== '') {
                $source = self::findRecord($ctx, $runOfId);
                if ($source === null || $source['type'] !== 'checklist') return ['error' => 'A run can only be made from an existing checklist.'];
            }
            $type = (string)($p['type'] ?? ($tpl['type'] ?? ($source ? 'checklist' : 'markdown')));
            if ($tpl !== null) $type = $tpl['type'];
            if (!NotebookFormat::isType($type)) return ['error' => "Unknown artefact type '$type'."];
            $ext = NotebookFormat::extFor($type);
            $area = (string)($p['area'] ?? 'inbox');
            if (!in_array($area, ['inbox', 'working', 'notes'], true)) return ['error' => 'A new artefact goes in inbox, working or notes.'];
            $confirm = !empty($p['confirmSecret']) || !empty($author['confirmSecret']);

            $content = $p['content'] ?? '';
            if (is_array($content)) $content = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $content = (string)$content;
            $extras = [];
            $prefix = '';
            $body = '';
            if (NotebookFormat::isJsonType($type)) {
                $obj = [];
                if (trim($content) !== '') {
                    $obj = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) return ['error' => 'The content is not valid JSON.'];
                    if (isset($obj['_meta']) && is_array($obj['_meta'])) { $extras = $obj['_meta']; unset($obj['_meta']); }
                    if ($type === 'json' && (!is_array($obj) || array_keys($obj) === range(0, count($obj) - 1) && $obj !== [] || !array_key_exists('data', $obj))) {
                        $obj = ['data' => $obj];
                    }
                }
                [$norm, $err] = NotebookFormat::normaliseJsonContent($type, $obj === [] ? NotebookFormat::skeleton($type, (string)($p['title'] ?? '')) : $obj);
                if ($err !== null) return ['error' => $err];
                $body = NotebookFormat::encodeJson(NotebookFormat::fixMaps($norm));
            } else {
                if ($content !== '') {
                    $pp = NotebookFormat::parse($content, $ext);
                    if ($pp['meta'] !== null) $extras = $pp['meta'];
                    $body = $pp['body'];
                    $prefix = $pp['prefix'];
                } elseif ($source !== null) {
                    $body = NotebookFormat::resetChecklist($source['body']);
                } elseif ($tpl !== null) {
                    $body = $tpl['body'];
                }
            }
            $title = trim((string)($p['title'] ?? ''));
            if ($title === '') $title = trim((string)($extras['title'] ?? ($tpl['title'] ?? '')));
            if ($title === '' && !NotebookFormat::isJsonType($type)) $title = NotebookFormat::firstHeading($body);
            if ($title === '' && $source !== null) $title = (string)($source['meta']['title'] ?? ''); // a run takes its checklist's title
            if ($title === '') return ['error' => 'A title is needed.'];
            if (mb_strlen($title) > 200) return ['error' => 'The title is longer than 200 characters.'];
            if ($source !== null && trim((string)($p['title'] ?? '')) === '') $title = $source['meta']['title'];
            if ($body === '' && !NotebookFormat::isJsonType($type)) {
                $sk = NotebookFormat::skeleton($type, $title);
                $body = is_string($sk) ? $sk : '';
            }
            if ($type === 'checklist') {
                [$body, $err] = NotebookFormat::numberItems($body);
                if ($err !== null) return ['error' => $err];
            }
            $tags = self::cleanTags($p['tags'] ?? ($extras['tags'] ?? ($tpl['tags'] ?? [])));
            if ($tags === null) return ['error' => 'tags must be a list of short words.'];

            $res = self::withLock($ctx, function () use ($ctx, $p, $author, $type, $ext, $area, $title, $tags, $body, $prefix, $extras, $confirm, $source, $runOfId, $tpl) {
                if ($err = self::ensureNotebook($ctx)) return ['error' => $err];
                $recs = self::scan($ctx);
                if (count($recs) >= self::MAX_ARTEFACTS) return ['error' => 'This notebook holds ' . self::MAX_ARTEFACTS . ' artefacts, which is the limit. Archive or move some out first.'];
                $used = [];
                foreach ($recs as $r) $used[$r['id']] = true;
                do { $id = 'a_' . bin2hex(random_bytes(6)); } while (isset($used[$id]));
                $now = NotebookFormat::now();
                $meta = [
                    'id' => $id, 'title' => $title, 'type' => $type, 'tags' => $tags, 'owner' => self::ownerOf($author),
                    'createdAt' => $now, 'updatedAt' => $now, 'pinned' => !empty($p['pinned']),
                ];
                if (!empty($p['needsYou'])) $meta['needsYou'] = true;
                // #664 R-149: the vault is written before the card file. Every refusal after a
                // vault write clears what was written, so no orphan secret is left for a card
                // that does not exist.
                /** @var list<string> $vaulted Secret fields written before a later refusal. */
                $vaulted = [];
                $fail = function (array $err) use (&$vaulted, $ctx, $id): array {
                    foreach ($vaulted as $field) self::vaultClear($ctx, $id, $field);
                    return $err;
                };
                // Keep extra meta keys a caller supplied, except the ones this writer owns.
                foreach ($extras as $k => $v) {
                    if (in_array($k, ['id', 'type', 'title', 'tags', 'owner', 'createdAt', 'updatedAt', 'pinned', 'needsYou', 'secrets', 'editedBy'], true)) continue;
                    // Only a person locks a page (section 10).
                    if (in_array($k, ['locked', 'lockedBy'], true) && ($author['kind'] ?? '') === 'agent') continue;
                    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/D', (string)$k)) $meta[$k] = $v;
                }
                // A template may carry meta keys (wiki-home: kind home; the section templates: section).
                if ($tpl !== null) {
                    foreach ((array)($tpl['meta'] ?? []) as $k => $v) if (!array_key_exists($k, $meta)) $meta[$k] = $v;
                }
                if ($type === 'connection') {
                    $meta['urls'] = self::cleanUrls($p['urls'] ?? ($extras['urls'] ?? []));
                    $meta['username'] = mb_substr((string)($p['username'] ?? ($extras['username'] ?? '')), 0, 200);
                    $meta['notes'] = mb_substr((string)($p['notes'] ?? ($extras['notes'] ?? '')), 0, 4000);
                    $meta['lastVerified'] = '';
                    if (!empty($p['secrets']) && is_array($p['secrets'])) {
                        if ($ctx['scope'] === 'global') return ['error' => 'A global card cannot hold a secret. Use a project notebook.'];
                        $refs = [];
                        foreach ($p['secrets'] as $field => $value) {
                            if (!in_array($field, NotebookFormat::SECRET_FIELDS, true)) return $fail(['error' => "'$field' is not a secret field. Use password, token or apiKey."]);
                            if (!is_string($value) || $value === '') continue;
                            if ($e = self::vaultSet($ctx, $id, (string)$field, $value)) return $fail(['error' => $e]);
                            $vaulted[] = (string)$field;
                            $refs[$field] = self::vaultRef($ctx, $id, (string)$field);
                        }
                        if ($refs) $meta['secrets'] = $refs;
                    }
                }
                if ($source !== null) {
                    $n = 2;
                    foreach ($recs as $r) if (($r['meta']['runOf'] ?? '') === $runOfId) $n++;
                    $meta['runOf'] = $runOfId;
                    if (trim((string)($p['title'] ?? '')) === '') $meta['title'] = $source['meta']['title'] . " (run $n)";
                    $baseSlug = NotebookFormat::slug($source['meta']['title']) . '-run-' . $n;
                } else {
                    $baseSlug = NotebookFormat::slug($meta['title']);
                }
                $raw = NotebookFormat::build($meta, $body, $ext, $prefix);
                if (strlen($raw) > self::MAX_FILE_BYTES) return $fail(['error' => 'This artefact is larger than ' . self::limitLabel() . ', which is the limit.']);
                if (!$confirm && ($hit = NotebookSecretGuard::scan($raw)) !== null) return $fail(self::secretRefusal($hit));
                $name = self::uniqueName($ctx, $baseSlug, $ext, $id);
                $abs = $ctx['root'] . '/' . $area . '/' . $name;
                if (!AtomicWriteService::write($abs, $raw)) return $fail(['error' => self::writeError($abs)]);
                self::$gitCache = [];
                return ['id' => $id, 'raw' => $raw];
            });
            if (isset($res['error'])) return $res;
            $id = $res['id'];
            if (($author['kind'] ?? '') !== 'agent') self::markSeen($ctx, $id, self::h12($res['raw']));
            $open = !empty($p['open']) && $ctx['settings']['autoOpen'] === '1';
            self::publish($ctx, $id, 'created', ['title' => $title, 'by' => self::byLabel($author), 'area' => $area, 'open' => $open]);
            // ARTEFACTS.md "Attention": an agent that adds something that needs the person may
            // announce it in its own voice, only when the person turned that on.
            if (($author['kind'] ?? '') === 'agent' && $ctx['settings']['spokenNotice'] === '1' && (!empty($p['needsYou']) || ($p['type'] ?? '') === 'form')) {
                self::speakNotice($title, $author);
            }
            $m = self::metaOne($ctx, $id);
            if (isset($m['error'])) return $m;
            return ['artefact' => $m, 'link' => 'artefact:' . $id, 'open' => $open];
        } catch (\Throwable $e) {
            self::log('create failed: ' . $e->getMessage());
            return ['error' => 'The artefact could not be created.'];
        }
    }

    public static function save(array $ctx, string $id, string $raw, string $expectedVersion, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if (strlen($raw) > self::MAX_FILE_BYTES) return ['error' => 'This artefact is larger than ' . self::limitLabel() . ', which is the limit.'];
        $confirm = !empty($author['confirmSecret']);
        return self::mutate($ctx, $id, $expectedVersion, $author, 'updated', [], $confirm, function (array $rec) use ($raw) {
            $p = NotebookFormat::parse($raw, $rec['ext']);
            $json = self::isJsonRec($rec);
            if ($json) {
                $obj = json_decode($raw, false);
                if (!($obj instanceof \stdClass)) return ['error' => 'The file is not a valid JSON object. Nothing was saved.'];
                if ($p['meta'] === null) { unset($obj->_meta); $p['body'] = NotebookFormat::encodeJson($obj); }
            }
            if ($p['meta'] === null) {
                $meta = $rec['meta'];
                $body = $p['body'];
                $prefix = $rec['prefix'];
            } else {
                $meta = self::normaliseMeta($p['meta'], $rec);
                if ($meta['id'] !== $rec['id']) return ['error' => "The metadata block changed the id. The id must stay '{$rec['id']}'. Nothing was saved."];
                if ($meta['type'] !== $rec['type']) return ['error' => "The metadata block changed the type from {$rec['type']}. A type cannot change. Nothing was saved."];
                $body = $p['body'];
                $prefix = $p['prefix'];
            }
            if ($rec['type'] === 'checklist') {
                [$body, $err] = NotebookFormat::numberItems($body);
                if ($err !== null) return ['error' => $err];
            }
            if ($rec['type'] === 'connection') {
                // A secret reference is only valid when the vault holds it; never let a file invent one.
                $meta['secrets'] = array_intersect_key((array)($meta['secrets'] ?? []), (array)($rec['meta']['secrets'] ?? []));
            }
            return ['meta' => $meta, 'body' => $body, 'prefix' => $prefix];
        });
    }

    public static function patch(array $ctx, string $id, array $ops, ?string $expectedVersion, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if ($ops === [] || count($ops) > 50) return ['error' => 'Send between 1 and 50 operations.'];
        $kinds = [];
        foreach ($ops as $op) {
            if (!is_array($op) || empty($op['op']) || !is_string($op['op'])) return ['error' => 'Each operation needs an "op" name.'];
            $kinds[$op['op']] = true;
        }
        $kind = 'updated';
        if (count($kinds) === 1) {
            if (isset($kinds['setItemState'])) $kind = 'item';
            elseif (isset($kinds['answer'])) $kind = 'answer';
        }
        $confirm = !empty($author['confirmSecret']);
        return self::mutate($ctx, $id, $expectedVersion, $author, $kind, [], $confirm, function (array $rec) use ($ops, $author, $ctx) {
            $meta = $rec['meta'];
            $body = $rec['body'];
            $type = $rec['type'];
            $json = NotebookFormat::isJsonType($type);
            $data = null;
            if ($json) {
                $data = json_decode($body === '' ? '{}' : $body, true);
                if (!is_array($data)) return ['error' => 'This file does not hold a valid JSON object.'];
            }
            $at = NotebookFormat::now();
            foreach ($ops as $i => $op) {
                $name = (string)$op['op'];
                $where = count($ops) > 1 ? ' (operation ' . ($i + 1) . ')' : '';
                $err = self::applyOp($ctx, $rec, $meta, $body, $data, $op, $author, $at);
                if ($err !== null) return ['error' => $err . $where];
            }
            if ($json) $body = NotebookFormat::encodeJson(NotebookFormat::fixMaps($data));
            if ($type === 'checklist') {
                [$body, $err] = NotebookFormat::numberItems($body);
                if ($err !== null) return ['error' => $err];
            }
            return ['meta' => $meta, 'body' => $body, 'prefix' => $rec['prefix']];
        });
    }

    /** Apply one patch operation to $meta/$body/$data. @return ?string error */
    private static function applyOp(array $ctx, array $rec, array &$meta, string &$body, ?array &$data, array $op, array $author, string $at): ?string {
        $type = $rec['type'];
        $name = (string)$op['op'];
        $isMd = in_array($type, ['markdown', 'checklist', 'connection', 'deck'], true);
        switch ($name) {
            case 'append': {
                $text = $op['text'] ?? null;
                if (!is_string($text) || $text === '') return 'append needs some text.';
                if (strlen($text) > 100000) return 'append text is longer than 100 KB.';
                if ($type === 'progress') return NotebookFormat::setProgress($data, ['appendLog' => $text]);
                if (NotebookFormat::isJsonType($type)) return "append is not supported for a $type artefact. Use patch operations for its fields.";
                if ($body !== '' && substr($body, -1) !== "\n") $body .= "\n";
                $body .= $text . (substr($text, -1) === "\n" ? '' : "\n");
                return null;
            }
            case 'replaceSection': {
                if (!$isMd) return "replaceSection works on Markdown artefacts, not on $type.";
                $h = $op['heading'] ?? null; $t = $op['text'] ?? null;
                if (!is_string($h) || trim($h) === '' || !is_string($t)) return 'replaceSection needs a heading and text.';
                $body = NotebookFormat::replaceSection($body, $h, $t);
                return null;
            }
            case 'setItemState': {
                if ($type !== 'checklist') return 'setItemState works on a checklist.';
                $item = (string)($op['item'] ?? ''); $res = (string)($op['result'] ?? '');
                $note = array_key_exists('note', $op) && $op['note'] !== null ? (string)$op['note'] : null;
                if ($note !== null && strlen($note) > 4000) return 'The note is longer than 4000 characters.';
                [$nb, $err] = NotebookFormat::setItemState($body, $item, $res, $note, self::byLabel($author), $at);
                if ($err !== null) return $err;
                $body = (string)$nb;
                return null;
            }
            case 'setTitle': {
                $t = trim((string)($op['title'] ?? ''));
                if ($t === '' || mb_strlen($t) > 200) return 'The title must be 1 to 200 characters.';
                $meta['title'] = $t;
                return null;
            }
            case 'setTags': {
                $tags = self::cleanTags($op['tags'] ?? null);
                if ($tags === null) return 'tags must be a list of short words.';
                $meta['tags'] = $tags;
                return null;
            }
            case 'setField': return self::applySetField($ctx, $rec, $meta, $data, (string)($op['field'] ?? ''), $op['value'] ?? null, $author);
            case 'answer': {
                if ($type !== 'form') return 'answer works on a form.';
                $v = $op['value'] ?? null;
                $note = isset($op['note']) ? (string)$op['note'] : null;
                if (is_string($v) && strlen($v) > 0 && ($v[0] === '[' || $v[0] === '{')) {
                    $d = json_decode($v, true);
                    if (json_last_error() === JSON_ERROR_NONE) $v = $d;
                }
                return NotebookFormat::answerForm($data, (string)($op['question'] ?? ''), $v, $note, (string)($author['kind'] ?? 'human'), $at);
            }
            case 'moveCard':
                if ($type !== 'board') return 'moveCard works on a board.';
                return NotebookFormat::moveCard($data, (string)($op['card'] ?? ''), (string)($op['toColumn'] ?? ''), (int)($op['index'] ?? 0));
            case 'addCard':
                if ($type !== 'board') return 'addCard works on a board.';
                if (!is_array($op['card'] ?? null)) return 'addCard needs a card object.';
                return NotebookFormat::addCard($data, (string)($op['column'] ?? ''), $op['card']);
            case 'setProgress':
                if ($type !== 'progress') return 'setProgress works on a progress artefact.';
                return NotebookFormat::setProgress($data, $op);
            case 'addMedia': {
                if ($type !== 'media') return 'addMedia works on a media artefact.';
                $asset = (string)($op['asset'] ?? '');
                // #677: a newline is not part of a file name here. The class excludes it, and D stops $ before a final newline.
                if (!preg_match('#^assets/[^/\0\n]+$#D', $asset) || strpos($asset, '..') !== false) return 'The asset must be a file inside assets/.';
                $kind = (string)($op['kind'] ?? 'file');
                if (!in_array($kind, ['image', 'video', 'audio', 'pdf', 'file'], true)) $kind = 'file';
                $data['items'][] = ['asset' => $asset, 'caption' => (string)($op['caption'] ?? ''), 'kind' => $kind];
                return null;
            }
        }
        return "Unknown operation '$name'.";
    }

    /** @return ?string error */
    private static function applySetField(array $ctx, array $rec, array &$meta, ?array &$data, string $field, $value, array $author = []): ?string {
        $type = $rec['type'];
        if ($field === '') return 'setField needs a field name.';
        if ($field === 'editedBy') return "The field 'editedBy' is set by the plugin and cannot be changed.";
        if ($field === 'locked' || $field === 'lockedBy') {
            if (($author['kind'] ?? 'human') === 'agent') return 'Only a person can lock or unlock a page. Add a comment with your suggestion instead.';
            if ($field === 'lockedBy') return "The field 'lockedBy' is set by the lock button and cannot be changed.";
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) { $meta['locked'] = true; if (empty($meta['lockedBy'])) $meta['lockedBy'] = (string)($author['name'] ?? '') ?: 'Person'; }
            else { unset($meta['locked'], $meta['lockedBy']); }
            return null;
        }
        if (in_array($field, NotebookFormat::SECRET_FIELDS, true)) {
            if ($type !== 'connection') return 'Only a connection card can hold a secret.';
            if ($ctx['scope'] === 'global') return 'A global card cannot hold a secret. Use a project notebook.';
            if (!is_string($value)) return 'A secret value must be text.';
            $secrets = (array)($meta['secrets'] ?? []);
            if ($value === '') {
                if ($e = self::vaultClear($ctx, $rec['id'], $field)) return $e;
                unset($secrets[$field]);
            } else {
                if ($e = self::vaultSet($ctx, $rec['id'], $field, $value)) return $e;
                $secrets[$field] = self::vaultRef($ctx, $rec['id'], $field);
            }
            $meta['secrets'] = $secrets;
            return null;
        }
        if (in_array($field, ['id', 'type', 'createdAt', 'updatedAt', 'owner', 'secrets', '_meta'], true)) return "The field '$field' cannot be changed.";
        if ($field === 'title') { $t = trim((string)$value); if ($t === '' || mb_strlen($t) > 200) return 'The title must be 1 to 200 characters.'; $meta['title'] = $t; return null; }
        if ($field === 'tags') { $t = self::cleanTags($value); if ($t === null) return 'tags must be a list of short words.'; $meta['tags'] = $t; return null; }
        if ($field === 'pinned' || $field === 'needsYou') { $meta[$field] = (bool)filter_var($value, FILTER_VALIDATE_BOOLEAN); return null; }
        if ($type === 'connection') {
            switch ($field) {
                case 'username': $meta['username'] = mb_substr((string)$value, 0, 200); return null;
                case 'notes': $meta['notes'] = mb_substr((string)$value, 0, 4000); return null;
                case 'lastVerified': $meta['lastVerified'] = mb_substr((string)$value, 0, 40); return null;
                case 'urls': if (!is_array($value)) return 'urls must be a list of {label, url}.'; $meta['urls'] = self::cleanUrls($value); return null;
            }
            return "A connection card has the fields username, notes, lastVerified, urls, password, token and apiKey, not '$field'.";
        }
        if (NotebookFormat::isJsonType($type)) {
            if ($data === null) return 'This file does not hold a valid JSON object.';
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,60}$/D', $field)) return "'$field' is not a valid field name.";
            $data[$field] = $value;
            return null;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/D', $field)) return "'$field' is not a valid field name.";
        if (!is_scalar($value) && !is_array($value) && $value !== null) return 'The value must be text, a number, true or false, a list or an object.';
        $meta[$field] = $value;
        return null;
    }

    public static function itemState(array $ctx, string $id, string $item, string $result, ?string $note, array $author): array {
        $op = ['op' => 'setItemState', 'item' => $item, 'result' => $result];
        if ($note !== null) $op['note'] = $note;
        return self::patch($ctx, $id, [$op], null, $author);
    }

    public static function answer(array $ctx, string $id, string $question, $value, ?string $note, array $author): array {
        $op = ['op' => 'answer', 'question' => $question, 'value' => $value];
        if ($note !== null) $op['note'] = $note;
        return self::patch($ctx, $id, [$op], null, $author);
    }

    public static function pin(array $ctx, string $id, bool $pinned, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        return self::mutate($ctx, $id, null, $author, 'updated', ['pinned' => $pinned], false, function (array $rec) use ($pinned) {
            $meta = $rec['meta'];
            $meta['pinned'] = $pinned;
            return ['meta' => $meta, 'body' => $rec['body'], 'prefix' => $rec['prefix'], 'keepEditedBy' => true];
        });
    }

    // ======================================================================
    // Move, archive, restore, purge
    // ======================================================================

    public static function move(array $ctx, string $id, string $area, array $author): array {
        return self::relocate($ctx, $id, $area, $author, 'move');
    }

    public static function archive(array $ctx, string $id, array $author): array {
        return self::relocate($ctx, $id, 'archive', $author, 'archive');
    }

    public static function restore(array $ctx, string $id, ?string $area, array $author): array {
        return self::relocate($ctx, $id, $area ?: 'notes', $author, 'restore');
    }

    private static function relocate(array $ctx, string $id, string $area, array $author, string $verb): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (!in_array($area, NotebookFormat::AREAS, true)) return ['error' => 'The area must be inbox, working, notes or archive.'];
            if ($verb === 'restore' && $area === 'archive') return ['error' => 'Restore moves an artefact out of the archive. Choose inbox, working or notes.'];
            if ($e = self::rateCheck($ctx, $author)) return ['error' => $e];
            $res = self::withLock($ctx, function () use ($ctx, $id, $area, $verb, $author) {
                $rec = self::findRecord($ctx, $id);
                if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
                if (self::lockedAgainst($rec, $author)) return self::lockedRefusal();
                if ($verb === 'restore' && $rec['area'] !== 'archive') return ['error' => 'This artefact is not archived.'];
                if ($rec['area'] === $area) return ['id' => $id, 'from' => $area, 'to' => $area, 'same' => true];
                $dir = $ctx['root'] . '/' . $area;
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['error' => 'The folder could not be created. The project folder may be read-only or full.'];
                $name = $rec['name'];
                if (file_exists($dir . '/' . $name)) {
                    $ext = $rec['ext'];
                    $base = substr($name, 0, -(strlen($ext) + 1));
                    $name = self::uniqueName($ctx, $base, $ext, $id);
                }
                if (!@rename($rec['abs'], $dir . '/' . $name)) return ['error' => 'The file could not be moved. The project folder may be read-only.'];
                self::$gitCache = [];
                return ['id' => $id, 'from' => $rec['area'], 'to' => $area, 'version' => $rec['version'], 'title' => $rec['meta']['title']];
            });
            if (isset($res['error'])) return $res;
            if (empty($res['same'])) {
                $kind = $res['to'] === 'archive' ? 'archived' : ($res['from'] === 'archive' ? 'restored' : 'moved');
                self::publish($ctx, $id, $kind, ['title' => $res['title'] ?? '', 'by' => self::byLabel($author), 'area' => $res['to']]);
            }
            $m = self::metaOne($ctx, $id);
            return isset($m['error']) ? $m : ['artefact' => $m];
        } catch (\Throwable $e) {
            self::log('move failed: ' . $e->getMessage());
            return ['error' => 'The artefact could not be moved.'];
        }
    }

    /** Writes a pending Tier-3 item for a person to approve in the tray. Removes nothing. */
    public static function purgeRequest(array $ctx, string $id, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $tool = 'aicli_purge_artefact';
            $opId = 'pending_' . $tool . '_' . bin2hex(random_bytes(4));
            $desc = 'Permanently remove the notebook file "' . $rec['meta']['title'] . '" (' . $rec['rel'] . ') from the notebook ' . $ctx['name']
                . '. Git is the only way back, and only if the file was committed.';
            $params = ['workspaceId' => $ctx['workspaceId'], 'id' => $id, 'path' => $rec['rel'], 'title' => $rec['meta']['title']];
            $caller = ['workspaceId' => (string)($author['workspaceId'] ?? ''), 'agentId' => (string)($author['agentId'] ?? ''), 'name' => (string)($author['name'] ?? '')];
            // ACTIVITY_TRAY_PLAIN_NOTICES.md R6: the tray title is a short question; the full
            // statement of what approval does goes in meta.statement, for the block under the title.
            $question = 'Remove the Notebook file "' . $rec['meta']['title'] . '" for good?';
            if (self::$activitySink !== null) {
                (self::$activitySink)($opId, $tool, $question, $params, $caller, $desc);
                $entry = true;
            } else {
                if (!class_exists('AICliAgents\Services\ActivityService', false)) require_once __DIR__ . '/ActivityService.php';
                $entry = ActivityService::propose($opId, $tool, $desc, $params, $caller, ['label' => $question]);
            }
            if ($entry === null) return ['error' => 'Could not create the pending approval item.'];
            return [
                'opId' => $opId, 'description' => $desc,
                'message' => 'Nothing has been removed. Waiting for a person to Approve or Reject this in the Manager UI, Activity tray.',
            ];
        } catch (\Throwable $e) {
            self::log('purgeRequest failed: ' . $e->getMessage());
            return ['error' => 'The purge request could not be created.'];
        }
    }

    /**
     * A person removes a note that is already in the Archive, for good (docs/specs/NOTEBOOK_LIST_QUICK_DELETE.md R5, R7).
     * The browser calls this when the Undo pill of a Delete runs out. An agent author is refused;
     * an agent still goes through purgeRequest and a person's approval. A note outside the Archive is refused.
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $author
     * @return array<string,mixed>
     */
    public static function remove(array $ctx, string $id, ?string $expectedVersion, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if (($author['kind'] ?? '') === 'agent') return ['error' => 'Only a person can remove a note for good. An agent proposes a purge and a person approves it.'];
        if (!preg_match('/^a_[0-9a-f]{12}$/D', $id)) return ['error' => "'$id' is not an artefact id."];
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            if ($rec['area'] !== 'archive') return ['error' => 'Only a note in the Archive can be removed for good. Archive it first.'];
            if ($expectedVersion !== null && $expectedVersion !== '' && $expectedVersion !== $rec['version']) return self::stale($rec['version']);
            $r = self::purgeExecute(['workspaceId' => (string)($ctx['workspaceId'] ?? ''), 'id' => $id]);
            if (isset($r['error'])) return $r;
            return ['purged' => true, 'id' => $id];
        } catch (\Throwable $e) {
            self::log('remove failed: ' . $e->getMessage());
            return ['error' => 'The note could not be removed.'];
        }
    }

    /**
     * Runs ONLY after a person approves the item in the tray (AdminService::approvePending).
     * Removes exactly one artefact file and its comments sidecar, after checking that the file
     * is still inside the notebook, and clears that artefact's vault secrets.
     * @param array<string,mixed> $params from purgeRequest: workspaceId, id
     */
    public static function purgeExecute(array $params): array {
        try {
            $ws = (string)($params['workspaceId'] ?? '');
            $id = (string)($params['id'] ?? '');
            if ($ws === '' || !preg_match('/^a_[0-9a-f]{12}$/D', $id)) return ['error' => 'This pending item has no valid workspace or artefact id.'];
            $ctx = self::contextFor($ws);
            if (isset($ctx['error'])) return ['error' => 'The notebook of this item is no longer available: ' . $ctx['error']];
            $res = self::withLock($ctx, function () use ($ctx, $id) {
                $rec = self::findRecord($ctx, $id);
                if ($rec === null) return ['error' => "The artefact '$id' no longer exists; nothing was removed."];
                $real = realpath($rec['abs']);
                $root = realpath($ctx['root']);
                if ($real === false || $root === false || strpos($real, $root . '/') !== 0 || is_link($rec['abs'])) {
                    return ['error' => 'The file is not inside the notebook any more. Nothing was removed.'];
                }
                if (!@unlink($real)) return ['error' => 'The file could not be removed. The project folder may be read-only.'];
                $side = self::commentsPath($ctx, $id);
                if (is_file($side)) @unlink($side);
                if ($rec['type'] === 'connection') {
                    foreach (array_keys((array)($rec['meta']['secrets'] ?? [])) as $field) self::vaultClear($ctx, $id, (string)$field);
                }
                self::$gitCache = [];
                return ['purged' => true, 'id' => $id, 'title' => $rec['meta']['title']];
            });
            if (isset($res['error'])) return $res;
            self::publish($ctx, $id, 'archived', ['title' => (string)$res['title'], 'by' => 'human', 'purged' => true]);
            return ['purged' => true, 'id' => $id];
        } catch (\Throwable $e) {
            self::log('purgeExecute failed: ' . $e->getMessage());
            return ['error' => 'The purge could not run.'];
        }
    }

    // ======================================================================
    // Comments
    // ======================================================================

    public static function addComment(array $ctx, string $id, string $kind, string $ref, string $text, ?string $parent, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (!in_array($kind, ['file', 'block', 'item', 'node', 'line', 'stop', 'slide'], true)) return ['error' => 'The comment kind must be file, block, item, node, line, stop or slide.'];
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > 4000) return ['error' => 'A comment must be 1 to 4000 characters.'];
            if (mb_strlen($ref) > 200) return ['error' => 'The comment reference is longer than 200 characters.'];
            if ($e = self::rateCheck($ctx, $author)) return ['error' => $e];
            $by = ['kind' => ($author['kind'] ?? '') === 'agent' ? 'agent' : 'human', 'name' => (string)($author['name'] ?? '') ?: 'Person'];
            $res = self::withLock($ctx, function () use ($ctx, $id, $kind, $ref, $text, $parent, $by) {
                $rec = self::findRecord($ctx, $id);
                if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
                $doc = self::readComments($ctx, $id);
                $now = NotebookFormat::now();
                if ($parent !== null && $parent !== '') {
                    $found = null;
                    foreach ($doc['comments'] as $i => $c) if (($c['id'] ?? '') === $parent) { $found = $i; break; }
                    if ($found === null) return ['error' => "There is no comment '$parent' on this artefact."];
                    $doc['comments'][$found]['replies'][] = ['by' => $by, 'at' => $now, 'text' => $text];
                    $out = $doc['comments'][$found];
                } else {
                    do { $cid = 'c_' . bin2hex(random_bytes(4)); $clash = false; foreach ($doc['comments'] as $c) if (($c['id'] ?? '') === $cid) $clash = true; } while ($clash);
                    $out = ['id' => $cid, 'target' => ['kind' => $kind, 'ref' => $ref], 'by' => $by, 'at' => $now, 'text' => $text, 'resolved' => false, 'replies' => []];
                    $doc['comments'][] = $out;
                }
                if (count($doc['comments']) > 500) return ['error' => 'This artefact already has 500 comments, which is the limit.'];
                if (!AtomicWriteService::writeJson(self::commentsPath($ctx, $id), $doc)) return ['error' => 'The comment could not be saved.'];
                self::$gitCache = [];
                return ['comment' => $out, 'title' => $rec['meta']['title']];
            });
            if (isset($res['error'])) return $res;
            self::publish($ctx, $id, 'comment', ['title' => (string)$res['title'], 'by' => self::byLabel($author)]);
            return ['comment' => $res['comment']];
        } catch (\Throwable $e) {
            return ['error' => 'The comment could not be added.'];
        }
    }

    public static function resolveComment(array $ctx, string $id, string $commentId, bool $resolved, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $res = self::withLock($ctx, function () use ($ctx, $id, $commentId, $resolved) {
                if (self::findRecord($ctx, $id) === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
                $doc = self::readComments($ctx, $id);
                foreach ($doc['comments'] as $i => $c) {
                    if (($c['id'] ?? '') !== $commentId) continue;
                    if (!empty($c['resolved']) === $resolved) return ['ok' => true, 'comment' => $c];
                    $doc['comments'][$i]['resolved'] = $resolved;
                    if (!AtomicWriteService::writeJson(self::commentsPath($ctx, $id), $doc)) return ['error' => 'The comment could not be saved.'];
                    self::$gitCache = [];
                    return ['ok' => true, 'comment' => $doc['comments'][$i], 'changed' => true];
                }
                return ['error' => "There is no comment '$commentId' on this artefact."];
            });
            if (isset($res['error'])) return $res;
            if (!empty($res['changed'])) self::publish($ctx, $id, 'comment', ['by' => self::byLabel($author), 'resolved' => $resolved]);
            return ['ok' => true, 'comment' => $res['comment']];
        } catch (\Throwable $e) {
            return ['error' => 'The comment could not be changed.'];
        }
    }

    // ======================================================================
    // Wiki layer, lock, journal (contract section 10)
    // ======================================================================

    /** A locked page refuses an AGENT author. A person is never refused. */
    private static function lockedAgainst(array $rec, array $author): bool {
        return !empty($rec['meta']['locked']) && ($author['kind'] ?? '') === 'agent';
    }

    private static function lockedRefusal(): array {
        return ['error' => self::LOCKED_MESSAGE, 'locked' => true];
    }

    /** Lock or unlock a page. People only: an agent is refused. Comments stay open on a locked page. */
    public static function lock(array $ctx, string $id, bool $locked, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if (($author['kind'] ?? '') === 'agent') return ['error' => 'Only a person can lock or unlock a page.'];
        $who = trim((string)($author['name'] ?? '')) ?: 'Person';
        return self::mutate($ctx, $id, null, $author, 'updated', ['action' => $locked ? 'locked' : 'unlocked'], false, function (array $rec) use ($locked, $who) {
            $meta = $rec['meta'];
            if ($locked) { $meta['locked'] = true; $meta['lockedBy'] = mb_substr($who, 0, 100); }
            else unset($meta['locked'], $meta['lockedBy']);
            return ['meta' => $meta, 'body' => $rec['body'], 'prefix' => $rec['prefix'], 'keepEditedBy' => true];
        });
    }

    /** The legacy wiki section of a record: meta `section`, else a tag `section:<name>`, else ''. It only decides membership now. */
    private static function sectionOf(array $rec): string {
        $s = $rec['meta']['section'] ?? '';
        if (is_string($s) && trim($s) !== '') return mb_substr(trim($s), 0, 80);
        foreach ((array)($rec['meta']['tags'] ?? []) as $t) {
            if (is_string($t) && stripos($t, 'section:') === 0 && trim(substr($t, 8)) !== '') return mb_substr(trim(substr($t, 8)), 0, 80);
        }
        return '';
    }

    /**
     * Is this record a wiki page? (docs/specs/NOTEBOOK_WIKI_LINK_STRUCTURE.md R1.)
     * Yes when it has the tag `wiki`, or the metadata kind `wiki`, or (legacy) a section.
     * Being linked from a wiki page does not make a page a wiki page.
     */
    public static function isWikiPage(array $rec): bool {
        foreach ((array)($rec['meta']['tags'] ?? []) as $t) {
            if (is_string($t) && strtolower(trim($t)) === 'wiki') return true;
        }
        $kind = $rec['meta']['kind'] ?? '';
        if (is_string($kind) && strtolower(trim($kind)) === 'wiki') return true;
        return self::sectionOf($rec) !== '';
    }

    /**
     * Ids that a Markdown body links to with [[a_ID]] or [[Title]] (or [[Title|label]]).
     * @param array<string,mixed> $byId  id => record
     * @param array<string,string> $byTitle  lower-case title => id
     * @return string[]
     */
    private static function linkTargets(string $text, array $byId, array $byTitle): array {
        if (strpos($text, '[[') === false) return [];
        // A [[x]] in a code fence or in inline code is text about the link syntax, not a link.
        $text = self::withoutCode($text);
        if (strpos($text, '[[') === false || !preg_match_all('/\[\[([^\[\]\r\n]{1,200})\]\]/', $text, $m)) return [];
        $out = [];
        foreach ($m[1] as $raw) {
            $t = trim(explode('|', $raw, 2)[0]);
            if ($t === '') continue;
            if (preg_match('/^a_[0-9a-f]{12}$/iD', $t)) {
                $id = strtolower($t);
                if (isset($byId[$id])) $out[$id] = true;
                continue;
            }
            $k = strtolower($t);
            if (isset($byTitle[$k])) $out[$byTitle[$k]] = true;
        }
        return array_keys($out);
    }

    /** The text with fenced code blocks and inline code spans removed (they hold no links). */
    private static function withoutCode(string $text): string {
        $keep = [];
        $fence = '';
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if ($fence === '') {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) { $fence = $m[1]; continue; }
                $keep[] = $line;
            } elseif (preg_match('/^ {0,3}(' . ($fence[0] === '`' ? '`' : '~') . '{' . strlen($fence) . ',})\s*$/', $line)) {
                $fence = '';
            }
        }
        $out = implode("\n", $keep);
        return (string)preg_replace('/(`+)(?!`).*?(?<!`)\1(?!`)/', '', $out);
    }

    /** Dated bullets of a "Things you should know" page, newest first. @return array<int,array{date:string,text:string}> */
    private static function knowNotes(string $body, int $max = 10): array {
        $notes = [];
        foreach (explode("\n", $body) as $line) {
            if (!preg_match('/^\s*[-*]\s+(.+?)\s*$/', $line, $m)) continue;
            $text = $m[1];
            $date = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)\s*(?:\x{2014}|-|:)\s*(.+)$/u', $text, $d)) { $date = $d[1]; $text = $d[2]; }
            $notes[] = ['date' => $date, 'text' => $text];
        }
        return array_slice(array_reverse($notes), 0, $max);
    }

    /**
     * The wiki tree (docs/specs/NOTEBOOK_WIKI_LINK_STRUCTURE.md R3, R4). Pure: it takes the wiki pages only.
     * Roots: the home page, then each entry page (no link in from a wiki page, at least one link out to a
     * wiki page), newest first. The children of a node are the wiki pages its text links to, in link order.
     * The search is breadth first, so each page sits once, under the first parent that reaches it:
     * the shallowest parent wins, a tie goes to the earlier root, then to the earlier link. A link to a
     * page that is already placed is ignored, so there are no cycles. Pages that only link in a loop
     * (no entry page reaches them) become roots too, newest first, after the entry pages.
     * A page with no link in and no link out to a wiki page is "unlinked".
     * @param array<string,array<string,mixed>> $pages  id => record (wiki pages only)
     * @param array<string,string[]> $outLinks  id => ids it links to, in link order (wiki targets only)
     * @return array{roots:string[],children:array<string,string[]>,unlinked:string[]}
     */
    private static function wikiTreeOf(array $pages, array $outLinks, ?string $homeId): array {
        $inCount = [];
        foreach ($outLinks as $from => $tos) foreach ($tos as $to) $inCount[$to] = ($inCount[$to] ?? 0) + 1;
        $newestFirst = static function (array $ids) use ($pages): array {
            usort($ids, static function ($a, $b) use ($pages) {
                $c = strcmp((string)$pages[$b]['meta']['updatedAt'], (string)$pages[$a]['meta']['updatedAt']);
                return $c !== 0 ? $c : strcmp($a, $b);
            });
            return $ids;
        };
        $quiet = static fn(string $id): bool => in_array($pages[$id]['meta']['kind'] ?? '', ['know', 'journal', 'journal-archive'], true);
        $entries = [];
        foreach ($pages as $id => $_) {
            if ($id === $homeId || $quiet($id)) continue;
            if (empty($inCount[$id]) && !empty($outLinks[$id])) $entries[] = $id;
        }
        $roots = $homeId !== null ? [$homeId] : [];
        foreach ($newestFirst($entries) as $id) $roots[] = $id;

        $placed = array_fill_keys($roots, true);
        $children = [];
        $grow = static function (array $queue) use (&$placed, &$children, $outLinks): void {
            for ($i = 0; $i < count($queue); $i++) {
                $id = $queue[$i];
                foreach ($outLinks[$id] ?? [] as $to) {
                    if (isset($placed[$to])) continue;
                    $placed[$to] = true;
                    $children[$id][] = $to;
                    $queue[] = $to;
                }
            }
        };
        $grow($roots);
        // A loop with no entry page: promote its newest page, then grow again.
        $loops = [];
        foreach ($pages as $id => $_) if (!isset($placed[$id]) && (!empty($outLinks[$id]) || !empty($inCount[$id])) && !$quiet($id)) $loops[] = $id;
        foreach ($newestFirst($loops) as $id) {
            if (isset($placed[$id])) continue;
            $placed[$id] = true;
            $roots[] = $id;
            $grow([$id]);
        }
        $unlinked = [];
        foreach ($pages as $id => $_) if (!isset($placed[$id]) && !$quiet($id)) $unlinked[] = $id;
        return ['roots' => $roots, 'children' => $children, 'unlinked' => $newestFirst($unlinked)];
    }

    /**
     * The wiki view of the notebook: home page, the tree of wiki pages built from their [[links]]
     * (`tree`: nested nodes), the wiki pages not in the tree (`unlinked`), a flat list of every wiki
     * page (`pages`), recent changes, the link graph and the "Things you should know" notes.
     * Only wiki pages (isWikiPage) are in `tree`, `unlinked`, `pages` and `recent`. Archived pages are left out.
     * @return array<string,mixed>
     */
    public static function wikiIndex(array $ctx): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $out = ['home' => null, 'tree' => [], 'unlinked' => [], 'pages' => [], 'recent' => [], 'graph' => ['nodes' => [], 'edges' => []], 'know' => ['unread' => 0, 'items' => []]];
            if (!is_dir($ctx['root'])) return $out;
            $recs = [];
            foreach (self::scan($ctx) as $r) if ($r['area'] !== 'archive') $recs[] = $r;
            $seen = self::readState($ctx)['seen'];
            $item = static fn(array $r): array => ['id' => $r['id'], 'title' => $r['meta']['title'], 'type' => $r['type'], 'updatedAt' => $r['meta']['updatedAt'], 'tags' => $r['meta']['tags']];
            $kindOf = static fn(array $r): string => is_string($r['meta']['kind'] ?? null) ? $r['meta']['kind'] : '';

            // Links: only text types hold [[links]]; any type can be a target.
            $byId = []; $byTitle = [];
            foreach ($recs as $r) {
                $byId[$r['id']] = $r;
                // Two pages with one title: the newest wins, as in the link in the page (MarkdownBody resolve()).
                // On equal times the first in scan order stays.
                $k = strtolower(trim($r['meta']['title']));
                if (!isset($byTitle[$k]) || strcmp((string)$r['meta']['updatedAt'], (string)$byId[$byTitle[$k]]['meta']['updatedAt']) > 0) $byTitle[$k] = $r['id'];
            }
            $textTypes = ['markdown', 'checklist', 'connection', 'deck'];
            $outEdges = []; $outOrdered = [];
            foreach ($recs as $r) {
                if (!in_array($r['type'], $textTypes, true)) continue;
                foreach (self::linkTargets($r['body'], $byId, $byTitle) as $to) {
                    if ($to === $r['id']) continue;
                    $outEdges[$r['id']][$to] = true;
                    $outOrdered[$r['id']][] = $to;
                }
            }

            // Wiki pages (R1) and the home page (R2: the newest page with kind home or the tag wiki-home).
            $wiki = [];
            foreach ($recs as $r) if (self::isWikiPage($r) || $kindOf($r) === 'home' || in_array('wiki-home', array_map('strtolower', $r['meta']['tags']), true)) $wiki[$r['id']] = $r;
            $home = null;
            foreach ($wiki as $r) {
                $isHome = $kindOf($r) === 'home' || in_array('wiki-home', array_map('strtolower', $r['meta']['tags']), true);
                if ($isHome && ($home === null || strcmp((string)$r['meta']['updatedAt'], (string)$home['meta']['updatedAt']) > 0)) $home = $r;
            }
            $out['home'] = $home['id'] ?? null;

            // Tree: links between wiki pages only. A link to a note that is not a wiki page is ignored.
            $wikiOut = [];
            foreach ($outOrdered as $from => $tos) {
                if (!isset($wiki[$from])) continue;
                foreach ($tos as $to) if (isset($wiki[$to])) $wikiOut[$from][] = $to;
            }
            $t = self::wikiTreeOf($wiki, $wikiOut, $out['home']);
            $node = function (string $id) use (&$node, $t, $wiki, $item): array {
                $n = $item($wiki[$id]);
                $n['children'] = array_map($node, $t['children'][$id] ?? []);
                return $n;
            };
            $out['tree'] = array_map($node, $t['roots']);
            foreach ($t['unlinked'] as $id) $out['unlinked'][] = $item($wiki[$id]);
            foreach ($wiki as $r) {
                $flags = [];
                if ($home !== null && $r['id'] === $home['id']) $flags[] = 'home';
                if ($kindOf($r) === 'know') $flags[] = 'know';
                if (in_array($kindOf($r), ['journal', 'journal-archive'], true)) $flags[] = 'journal';
                if (!empty($r['meta']['locked'])) $flags[] = 'locked';
                $out['pages'][] = $item($r) + ['flags' => $flags];
            }

            // Recently changed wiki pages.
            $sorted = array_values($wiki);
            usort($sorted, static fn($a, $b) => strcmp((string)$b['meta']['updatedAt'], (string)$a['meta']['updatedAt']));
            foreach (array_slice($sorted, 0, 20) as $r) $out['recent'][] = $item($r);

            // Graph: pages with at least one link, the most linked 300.
            $deg = [];
            foreach ($outEdges as $from => $tos) foreach ($tos as $to => $_) { $deg[$from] = ($deg[$from] ?? 0) + 1; $deg[$to] = ($deg[$to] ?? 0) + 1; }
            arsort($deg);
            $keep = array_slice(array_keys($deg), 0, 300);
            $keepSet = array_flip($keep);
            foreach ($keep as $id) $out['graph']['nodes'][] = ['id' => $id, 'title' => $byId[$id]['meta']['title'], 'type' => $byId[$id]['type']];
            foreach ($outEdges as $from => $tos) foreach ($tos as $to => $_) {
                if (isset($keepSet[$from], $keepSet[$to])) $out['graph']['edges'][] = ['from' => $from, 'to' => $to];
            }

            // Things you should know: unread until a person has seen this version of the page.
            foreach ($recs as $r) {
                if ($kindOf($r) !== 'know') continue;
                $unread = ($seen[$r['id']] ?? null) !== $r['version'];
                if ($unread) $out['know']['unread']++;
                $out['know']['items'][] = $item($r) + ['unread' => $unread, 'notes' => self::knowNotes($r['body'])];
            }
            return $out;
        } catch (\Throwable $e) {
            self::log('wikiIndex failed: ' . $e->getMessage());
            return ['error' => 'The wiki index could not be built.'];
        }
    }

    /**
     * Add one dated line to the "Agent journal" page (area working, meta kind journal), created on first use.
     * Off unless the setting notebook_journal is 1. The page keeps the newest 500 entries; older lines move
     * to archive/agent-journal-<yyyy-mm>.md and are never deleted.
     */
    public static function journalAppend(array $ctx, string $text, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (($ctx['settings']['journal'] ?? '0') !== '1') return ['error' => 'The agent journal is turned off. A person turns it on in Settings > Notebook.'];
            $text = trim((string)preg_replace('/\s+/u', ' ', $text));
            if ($text === '') return ['error' => 'Write what you did and why, in one or two sentences.'];
            if (mb_strlen($text) > self::JOURNAL_MAX_CHARS) return ['error' => 'A journal entry can have at most ' . self::JOURNAL_MAX_CHARS . ' characters. Shorten it and try again.'];
            $name = trim((string)preg_replace('/\s+/u', ' ', (string)($author['name'] ?? '')));
            if ($name === '') $name = ($author['kind'] ?? '') === 'agent' ? 'agent' : 'Person';
            $line = '- ' . date('Y-m-d H:i') . " \u{2014} " . mb_substr($name, 0, 60) . ': ' . $text;

            $id = null;
            if (is_dir($ctx['root'])) {
                foreach (self::scan($ctx) as $r) {
                    if ($r['area'] === 'archive') continue;
                    $isJ = ($r['meta']['kind'] ?? '') === 'journal'
                        || ($r['area'] === 'working' && $r['type'] === 'markdown' && strtolower($r['meta']['title']) === strtolower(self::JOURNAL_TITLE));
                    if ($isJ) { $id = $r['id']; break; }
                }
            }
            if ($id === null) {
                $made = self::create($ctx, [
                    'title' => self::JOURNAL_TITLE, 'type' => 'markdown', 'area' => 'working', 'tags' => ['journal'],
                    'content' => "---\nkind: journal\nsection: Agent journal\n---\n# " . self::JOURNAL_TITLE . "\n\nWhat the agents did and why. One line for each entry, newest at the bottom.\n\n",
                ], $author);
                if (isset($made['error'])) return $made;
                $id = (string)$made['artefact']['id'];
            }
            $res = self::mutate($ctx, $id, null, $author, 'updated', ['journal' => true], false, function (array $rec) use ($line, $ctx, $author) {
                $body = $rec['body'];
                if ($body !== '' && substr($body, -1) !== "\n") $body .= "\n";
                $body .= $line . "\n";
                $lines = explode("\n", $body);
                $idx = [];
                foreach ($lines as $i => $l) if (preg_match('/^- \d{4}-\d{2}-\d{2} \d{2}:\d{2} \x{2014} /u', $l)) $idx[] = $i;
                $over = count($idx) - self::JOURNAL_KEEP;
                if ($over > 0) {
                    $byMonth = [];
                    foreach (array_slice($idx, 0, $over) as $i) {
                        $byMonth[substr($lines[$i], 2, 7)][] = $lines[$i];
                        unset($lines[$i]);
                    }
                    foreach ($byMonth as $ym => $moved) {
                        if ($e = self::journalArchive($ctx, (string)$ym, $moved, $author)) return ['error' => $e];
                    }
                    $body = implode("\n", array_values($lines));
                }
                return ['meta' => $rec['meta'], 'body' => $body, 'prefix' => $rec['prefix']];
            });
            if (isset($res['error'])) return $res;
            return ['ok' => true, 'id' => $id, 'entry' => $line];
        } catch (\Throwable $e) {
            self::log('journalAppend failed: ' . $e->getMessage());
            return ['error' => 'The journal entry could not be saved.'];
        }
    }

    /** Append moved journal lines to archive/agent-journal-<yyyy-mm>.md (skips lines already there). Runs inside the notebook lock. @return ?string error */
    private static function journalArchive(array $ctx, string $ym, array $lines, array $author): ?string {
        if (!preg_match('/^\d{4}-\d{2}$/D', $ym)) return 'A journal line has an unreadable date, so it was not archived.';
        $dir = $ctx['root'] . '/archive';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return 'The archive folder could not be created.';
        $name = 'agent-journal-' . $ym . '.md';
        $abs = $dir . '/' . $name;
        $now = NotebookFormat::now();
        if (is_file($abs)) {
            $p = NotebookFormat::parse((string)@file_get_contents($abs), 'md');
            if (!is_array($p['meta'])) return "The archive file $name exists but is not a notebook page.";
            $meta = $p['meta'];
            $body = $p['body'];
        } else {
            $meta = [
                'id' => 'a_' . bin2hex(random_bytes(6)), 'title' => self::JOURNAL_TITLE . ' archive ' . $ym, 'type' => 'markdown', 'tags' => ['journal'],
                'owner' => self::ownerOf($author), 'createdAt' => $now, 'updatedAt' => $now, 'pinned' => false, 'kind' => 'journal-archive',
            ];
            $body = '# ' . self::JOURNAL_TITLE . ' archive ' . $ym . "\n\nOlder entries moved here from the Agent journal. Nothing is deleted.\n\n";
        }
        $have = array_flip(explode("\n", $body));
        if ($body !== '' && substr($body, -1) !== "\n") $body .= "\n";
        foreach ($lines as $l) if (!isset($have[$l])) $body .= $l . "\n";
        $meta['updatedAt'] = $now;
        $meta = NotebookFormat::orderMeta(self::normaliseMeta($meta, ['ext' => 'md', 'name' => $name, 'mtime' => time()]));
        if (!AtomicWriteService::write($abs, NotebookFormat::build($meta, $body, 'md'))) return self::writeError($abs);
        self::$gitCache = [];
        return null;
    }

    // ======================================================================
    // Read state
    // ======================================================================

    public static function markRead(array $ctx, ?string $id, bool $all): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $recs = self::scan($ctx);
            $targets = [];
            foreach ($recs as $r) if ($all || $r['id'] === $id) $targets[] = $r;
            if (!$all && $targets === []) return ['error' => "There is no artefact with id '" . (string)$id . "' in this notebook."];
            $human = self::author(null);
            foreach ($targets as $r) {
                // Clear needsYou only for a form that is already answered.
                if (!empty($r['meta']['needsYou']) && $r['type'] === 'form') {
                    $d = json_decode($r['body'], true);
                    if (is_array($d) && ($d['status'] ?? '') === 'answered' && $r['area'] !== 'archive') {
                        $res = self::mutate($ctx, $r['id'], null, $human, 'updated', [], false, function (array $rec) {
                            $meta = $rec['meta'];
                            unset($meta['needsYou']);
                            return ['meta' => $meta, 'body' => $rec['body'], 'prefix' => $rec['prefix'], 'keepEditedBy' => true];
                        });
                        unset($res);
                        $fresh = self::findRecord($ctx, $r['id']);
                        if ($fresh !== null) $r = $fresh;
                    }
                }
                self::markSeen($ctx, $r['id'], $r['version']);
            }
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['error' => 'The read state could not be saved.'];
        }
    }

    // ======================================================================
    // Secrets (connection cards)
    // ======================================================================

    public static function secretSet(array $ctx, string $id, string $field, string $value, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if (!in_array($field, NotebookFormat::SECRET_FIELDS, true)) return ['error' => 'The field must be password, token or apiKey.'];
        $r = self::mutate($ctx, $id, null, $author, 'secret', ['action' => 'set', 'field' => $field], false, function (array $rec) use ($ctx, $field, $value) {
            if ($rec['type'] !== 'connection') return ['error' => 'Only a connection card can hold a secret.'];
            $meta = $rec['meta'];
            $err = self::applySetField($ctx, $rec, $meta, $nullData, $field, $value);
            if ($err !== null) return ['error' => $err];
            return ['meta' => $meta, 'body' => $rec['body'], 'prefix' => $rec['prefix']];
        });
        if (isset($r['error'])) return $r;
        return ['ok' => true];
    }

    public static function secretClear(array $ctx, string $id, string $field, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        if (!in_array($field, NotebookFormat::SECRET_FIELDS, true)) return ['error' => 'The field must be password, token or apiKey.'];
        $r = self::mutate($ctx, $id, null, $author, 'secret', ['action' => 'cleared', 'field' => $field], false, function (array $rec) use ($ctx, $field) {
            if ($rec['type'] !== 'connection') return ['error' => 'Only a connection card can hold a secret.'];
            $meta = $rec['meta'];
            $secrets = (array)($meta['secrets'] ?? []);
            if ($e = self::vaultClear($ctx, $rec['id'], $field)) return ['error' => $e];
            unset($secrets[$field]);
            $meta['secrets'] = $secrets;
            return ['meta' => $meta, 'body' => $rec['body'], 'prefix' => $rec['prefix']];
        });
        if (isset($r['error'])) return $r;
        return ['ok' => true];
    }

    /** A person reveals one field. An agent may not read a secret back. The audit entry never holds the value. */
    public static function secretReveal(array $ctx, string $id, string $field, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (($author['kind'] ?? '') !== 'human') return ['error' => 'An agent may not read a secret back. A person can reveal it in the Notebook.'];
            if (!in_array($field, NotebookFormat::SECRET_FIELDS, true)) return ['error' => 'The field must be password, token or apiKey.'];
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            if ($rec['type'] !== 'connection') return ['error' => 'Only a connection card holds secrets.'];
            if (empty($rec['meta']['secrets'][$field])) return ['error' => "This card has no $field stored."];
            $vault = self::vaultLoad($ctx);
            $key = self::vaultKey($id, $field);
            if (!isset($vault[$key]) || $vault[$key] === '') return ['error' => 'This secret is not on this server. Enter it again to store it here.', 'notOnServer' => true];
            self::publish($ctx, $id, 'secret', ['action' => 'revealed', 'field' => $field, 'by' => self::byLabel($author), 'title' => $rec['meta']['title']]);
            return ['value' => $vault[$key], 'expiresIn' => 15];
        } catch (\Throwable $e) {
            return ['error' => 'The secret could not be read.'];
        }
    }

    // ======================================================================
    // URL check
    // ======================================================================

    /** HEAD (then GET) a URL listed on a card. LAN, localhost and .local addresses only. */
    public static function checkUrl(array $ctx, string $id, string $url): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $listed = [];
            if ($rec['type'] === 'connection') foreach ((array)($rec['meta']['urls'] ?? []) as $u) $listed[] = (string)($u['url'] ?? '');
            if ($rec['type'] === 'link') { $d = json_decode($rec['body'], true); $listed[] = (string)($d['url'] ?? ''); }
            if (!in_array($url, $listed, true)) return ['error' => 'That address is not listed on this card, so it will not be checked.'];
            $parts = parse_url($url);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = (string)($parts['host'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') return ['error' => 'Only http and https addresses can be checked.'];
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $ip = self::resolvePrivate(trim($host, '[]'));
            if ($ip === null) return ['error' => 'Only addresses on your own network (private, localhost or .local) can be checked.'];
            if (!function_exists('curl_init')) return ['error' => 'This server has no curl support, so it cannot check addresses.'];
            $status = 0; $ms = 0;
            foreach (['HEAD', 'GET'] as $method) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 1048576,
                    CURLOPT_WRITEFUNCTION => static fn($c, $d) => strlen($d) > 65536 ? 0 : strlen($d),
                ]);
                $t0 = microtime(true);
                @curl_exec($ch);
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($ch);
                curl_close($ch);
                if ($status === 405 || $status === 501) continue;
                if ($status === 0 && $method === 'HEAD' && $errno !== 0 && $errno !== 28) continue;
                break;
            }
            $ok = $status >= 200 && $status < 400;
            if ($ok) {
                $now = NotebookFormat::now();
                $human = self::author(null);
                self::mutate($ctx, $id, null, $human, 'updated', [], false, function (array $r) use ($now, $status) {
                    $meta = $r['meta'];
                    $body = $r['body'];
                    if ($r['type'] === 'connection') {
                        $meta['lastVerified'] = $now;
                    } elseif ($r['type'] === 'link') {
                        $d = json_decode($body, true);
                        if (is_array($d)) { $d['checkedAt'] = $now; $d['status'] = (string)$status; $body = NotebookFormat::encodeJson($d); }
                    }
                    return ['meta' => $meta, 'body' => $body, 'prefix' => $r['prefix'], 'keepEditedBy' => true];
                });
            }
            return ['ok' => $ok, 'httpStatus' => $status, 'ms' => $ms];
        } catch (\Throwable $e) {
            return ['error' => 'The address could not be checked.'];
        }
    }

    /** The IP to pin for $host when it is private, loopback or .local; null otherwise. */
    private static function resolvePrivate(string $host): ?string {
        $lower = strtolower($host);
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if ($lower === 'localhost') $ips = ['127.0.0.1'];
            else {
                $r = @gethostbynamel($host);
                if (is_array($r)) $ips = $r;
            }
        }
        if ($ips === []) return null;
        foreach ($ips as $ip) if (!self::isPrivateIp($ip)) return null;
        return $ips[0];
    }

    private static function isPrivateIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($ip === '::1') return true;
            $b0 = ord($bin[0]);
            return ($b0 & 0xfe) === 0xfc || ($b0 === 0xfe && (ord($bin[1]) & 0xc0) === 0x80);
        }
        if (strncmp($ip, '127.', 4) === 0) return true;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && !preg_match('/^(0\.|169\.254\.|2(2[4-9]|[3-5]\d)\.)/', $ip);
    }

    // ======================================================================
    // Assets, export, import, share, read aloud
    // ======================================================================

    public static function assetPut(array $ctx, string $name, string $bytes): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $len = strlen($bytes);
            if ($len === 0) return ['error' => 'The file is empty.'];
            if ($len > self::MAX_ASSET_BYTES) return ['error' => 'The file is larger than ' . (self::MAX_ASSET_BYTES / 1048576) . ' MB, which is the limit for one asset.'];
            $name = basename(str_replace('\\', '/', $name));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::ASSET_EXT[$ext])) return ['error' => "Files of type '.$ext' are not accepted. Allowed: " . implode(', ', array_keys(self::ASSET_EXT)) . '.'];
            if (!self::magicOk($ext, $bytes)) return ['error' => "The content of this file does not look like a .$ext file."];
            $base = NotebookFormat::slug(pathinfo($name, PATHINFO_FILENAME), 60);
            $dir = $ctx['root'] . '/assets';
            $res = self::withLock($ctx, function () use ($ctx, $dir, $base, $ext, $bytes, $len) {
                if ($e = self::ensureNotebook($ctx)) return ['error' => $e];
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['error' => 'The assets folder could not be created.'];
                $total = 0;
                foreach (@scandir($dir) ?: [] as $f) if ($f[0] !== '.' && is_file("$dir/$f")) $total += (int)filesize("$dir/$f");
                if ($total + $len > self::MAX_ASSETS_TOTAL) return ['error' => 'The assets folder would pass ' . (self::MAX_ASSETS_TOTAL / 1048576) . ' MB, which is the limit.'];
                $file = "$base.$ext";
                $n = 0;
                while (file_exists("$dir/$file")) { $n++; $file = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 4) . ".$ext"; if ($n > 20) return ['error' => 'Could not find a free file name.']; }
                if (!AtomicWriteService::write("$dir/$file", $bytes)) return ['error' => self::writeError("$dir/$file")];
                self::$gitCache = [];
                return ['path' => 'assets/' . $file];
            });
            return $res;
        } catch (\Throwable $e) {
            return ['error' => 'The file could not be stored.'];
        }
    }

    /** A validated absolute path for `assets/<name>`, or null. */
    public static function assetPath(array $ctx, string $relPath): ?string {
        if (isset($ctx['error'])) return null;
        if (strpos($relPath, "\0") !== false || strncmp($relPath, 'assets/', 7) !== 0 || strpos($relPath, '..') !== false) return null;
        $assets = realpath($ctx['root'] . '/assets');
        if ($assets === false) return null;
        $real = realpath($ctx['root'] . '/' . $relPath);
        if ($real === false || strpos($real, $assets . '/') !== 0 || !is_file($real)) return null;
        return $real;
    }

    public static function assetMime(string $path): string {
        return self::ASSET_EXT[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** @return array{filename:string,mime:string,bytes:string}|array{error:string} */
    public static function export(array $ctx, ?string $id, ?string $area, string $format): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            if (!in_array($format, ['md', 'html', 'zip'], true)) return ['error' => 'The format must be md, html or zip.'];
            $recs = [];
            $label = 'notebook';
            if ($id !== null && $id !== '') {
                $r = self::findRecord($ctx, $id);
                if ($r === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
                $recs = [$r];
                $label = NotebookFormat::slug($r['meta']['title']);
            } elseif ($area !== null && $area !== '') {
                if ($area !== 'all' && !in_array($area, NotebookFormat::AREAS, true)) return ['error' => 'The area must be inbox, working, notes, archive or all.'];
                foreach (self::scan($ctx) as $r) if ($area === 'all' || $r['area'] === $area) $recs[] = $r;
                $label = ($ctx['scope'] === 'global' ? 'global' : NotebookFormat::slug($ctx['name'])) . '-' . $area;
                if ($recs === []) return ['error' => 'There is nothing to export in that area.'];
            } else {
                return ['error' => 'Give an artefact id or an area to export.'];
            }
            if ($format === 'zip') {
                if (!class_exists('ZipArchive')) return ['error' => 'This server has no zip support. Export as md or html instead.'];
                $tmp = tempnam(sys_get_temp_dir(), 'nbx');
                $zip = new \ZipArchive();
                if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) return ['error' => 'The zip file could not be created.'];
                $assets = [];
                foreach ($recs as $r) {
                    $zip->addFromString($r['rel'], $r['raw']);
                    $cf = self::commentsPath($ctx, $r['id']);
                    if (is_file($cf)) $zip->addFile($cf, '.comments/' . $r['id'] . '.json');
                    if (preg_match_all('#assets/[A-Za-z0-9._\-]+#', $r['raw'], $m)) foreach ($m[0] as $a) $assets[$a] = true;
                }
                foreach (array_keys($assets) as $a) {
                    $p = self::assetPath($ctx, $a);
                    if ($p !== null) $zip->addFile($p, $a);
                }
                $zip->close();
                $bytes = (string)file_get_contents($tmp);
                @unlink($tmp);
                return ['filename' => $label . '.zip', 'mime' => 'application/zip', 'bytes' => $bytes];
            }
            if ($format === 'md') {
                $parts = [];
                foreach ($recs as $r) $parts[] = count($recs) === 1 ? self::exportMarkdown($r) : "<!-- {$r['rel']} -->\n" . self::exportMarkdown($r, false);
                return ['filename' => $label . '.md', 'mime' => 'text/markdown; charset=utf-8', 'bytes' => implode("\n\n", $parts)];
            }
            $sections = '';
            foreach ($recs as $r) {
                $title = htmlspecialchars($r['meta']['title'], ENT_QUOTES);
                $inner = in_array($r['type'], ['markdown', 'checklist', 'connection', 'deck'], true)
                    ? self::markdownToHtml($r['body'])
                    : '<pre>' . htmlspecialchars($r['body'], ENT_QUOTES) . '</pre>';
                $sections .= "<section><h1>$title</h1>\n$inner\n</section>\n";
            }
            $html = "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>"
                . htmlspecialchars($label, ENT_QUOTES) . "</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem;color:#222}pre{background:#f4f4f4;padding:.75rem;overflow:auto}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:.25rem .5rem}blockquote{border-left:4px solid #ccc;margin-left:0;padding-left:1rem;color:#555}section{margin-bottom:3rem}</style></head><body>\n"
                . $sections . "</body></html>\n";
            return ['filename' => $label . '.html', 'mime' => 'text/html; charset=utf-8', 'bytes' => $html];
        } catch (\Throwable $e) {
            self::log('export failed: ' . $e->getMessage());
            return ['error' => 'The export failed.'];
        }
    }

    private static function exportMarkdown(array $r, bool $withMeta = true): string {
        if (in_array($r['type'], ['markdown', 'checklist', 'connection', 'deck'], true)) {
            if ($withMeta) return $r['raw'];
            return $r['body'];
        }
        $lang = ['mermaid' => 'mermaid', 'svg' => 'xml', 'html' => 'html', 'diff' => 'diff', 'log' => 'text'][$r['type']] ?? 'json';
        return '# ' . $r['meta']['title'] . "\n\n```$lang\n" . rtrim($r['body'], "\n") . "\n```\n";
    }

    public static function import(array $ctx, string $bytes, string $filename, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $imported = 0;
            $skipped = [];
            $confirm = !empty($author['confirmSecret']);
            if (strncmp($bytes, "PK\x03\x04", 4) === 0) {
                if (!class_exists('ZipArchive')) return ['error' => 'This server has no zip support, so a zip cannot be imported.'];
                $tmp = tempnam(sys_get_temp_dir(), 'nbi');
                file_put_contents($tmp, $bytes);
                $zip = new \ZipArchive();
                if ($zip->open($tmp) !== true) { @unlink($tmp); return ['error' => 'The zip file could not be opened.']; }
                if ($zip->numFiles > 2500) { $zip->close(); @unlink($tmp); return ['error' => 'The zip holds more than 2500 files.']; }
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) $total += (int)($zip->statIndex($i)['size'] ?? 0);
                if ($total > 20 * 1048576) { $zip->close(); @unlink($tmp); return ['error' => 'The zip would expand to more than 20 MB.']; }
                $existing = [];
                foreach (self::scan($ctx) as $r) $existing[$r['id']] = true;
                $comments = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string)$zip->getNameIndex($i);
                    $data = $zip->getFromIndex($i);
                    if ($data === false || substr($entry, -1) === '/') continue;
                    if (preg_match('#^(inbox|working|notes|archive)/([^/\0\n]+)$#D', $entry, $m) && strpos($m[2], '..') === false) {
                        $ext = strtolower(pathinfo($m[2], PATHINFO_EXTENSION));
                        if (!in_array($ext, NotebookFormat::EXTENSIONS, true)) { $skipped[] = ['name' => $entry, 'reason' => 'unknown file type']; continue; }
                        $r = self::importOne($ctx, $m[1], $m[2], $ext, $data, $existing, $confirm, false);
                        if ($r === null) $imported++; else $skipped[] = ['name' => $entry, 'reason' => $r];
                    } elseif (preg_match('#^\.comments/(a_[0-9a-f]{12})\.json$#D', $entry, $m)) {
                        $comments[$m[1]] = $data;
                    } elseif (preg_match('#^assets/([^/\0\n]+)$#D', $entry, $m) && strpos($m[1], '..') === false) {
                        $r = self::assetPut($ctx, $m[1], $data);
                        if (isset($r['error'])) $skipped[] = ['name' => $entry, 'reason' => $r['error']];
                    }
                }
                $zip->close();
                @unlink($tmp);
                foreach ($comments as $cid => $data) {
                    $f = self::commentsPath($ctx, $cid);
                    $j = json_decode($data, true);
                    if (!is_file($f) && is_array($j) && isset($j['comments']) && self::findRecord($ctx, $cid) !== null) AtomicWriteService::writeJson($f, ['schema' => 1, 'comments' => $j['comments']]);
                }
            } else {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, NotebookFormat::EXTENSIONS, true)) return ['error' => 'Import takes a .zip exported from a notebook, or a single .md, .mmd, .html, .svg, .json, .log or .diff file.'];
                $existing = [];
                foreach (self::scan($ctx) as $r) $existing[$r['id']] = true;
                $r = self::importOne($ctx, 'inbox', basename($filename), $ext, $bytes, $existing, $confirm, true);
                if ($r === null) $imported++; else return ['error' => $r];
            }
            if ($imported > 0) self::publish($ctx, '', 'created', ['by' => self::byLabel($author), 'imported' => $imported]);
            return ['imported' => $imported, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            self::log('import failed: ' . $e->getMessage());
            return ['error' => 'The import failed.'];
        }
    }

    /** @return ?string null on success, else a reason */
    private static function importOne(array $ctx, string $area, string $fname, string $ext, string $data, array &$existing, bool $confirm, bool $single): ?string {
        if (strlen($data) > self::MAX_FILE_BYTES) return 'larger than ' . self::limitLabel();
        if (!$confirm && ($hit = NotebookSecretGuard::scan($data)) !== null) return "looks like $hit";
        $res = self::withLock($ctx, function () use ($ctx, $area, $fname, $ext, $data, &$existing) {
            if ($e = self::ensureNotebook($ctx)) return ['error' => $e];
            if (count(self::scan($ctx)) >= self::MAX_ARTEFACTS) return ['error' => 'the notebook is full'];
            $p = NotebookFormat::parse($data, $ext);
            $now = NotebookFormat::now();
            $meta = $p['meta'];
            $body = $p['body'];
            if (NotebookFormat::isJsonType(NotebookFormat::typeForExt($ext)) || ($meta['type'] ?? '') && NotebookFormat::isJsonType((string)($meta['type'] ?? ''))) {
                if (!(json_decode($data, false) instanceof \stdClass)) return ['error' => 'not a JSON object'];
                if ($meta === null) { $o = json_decode($data, false); unset($o->_meta); $body = NotebookFormat::encodeJson($o); }
            }
            if (!is_array($meta) || !preg_match('/^a_[0-9a-f]{12}$/D', (string)($meta['id'] ?? ''))) {
                $title = NotebookFormat::firstHeading($body) ?: pathinfo($fname, PATHINFO_FILENAME);
                $meta = ['title' => $title, 'type' => NotebookFormat::typeForExt($ext), 'tags' => [], 'owner' => ['kind' => 'human', 'agentId' => '', 'name' => 'Person', 'workspace' => ''], 'createdAt' => $now, 'pinned' => false];
                $meta['id'] = 'a_' . bin2hex(random_bytes(6));
            } elseif (isset($existing[$meta['id']])) {
                return ['error' => 'already in this notebook'];
            }
            $type = (string)($meta['type'] ?? '');
            if (!NotebookFormat::isType($type) || NotebookFormat::extFor($type) !== $ext) $meta['type'] = $type = NotebookFormat::typeForExt($ext);
            $meta = self::normaliseMeta($meta, ['meta' => [], 'ext' => $ext, 'mtime' => time(), 'name' => $fname]);
            if (!empty($meta['secrets'])) unset($meta['secrets']); // vault references from another box point at nothing here
            $raw = NotebookFormat::build($meta, $body, $ext, $p['prefix']);
            // The notebook details make the file bigger. scan() skips a file over the limit, so a file that
            // grew over it would be saved and then be invisible. Refuse it before it is written.
            if (strlen($raw) > self::MAX_FILE_BYTES) return ['error' => 'larger than ' . self::limitLabel() . ' once the notebook details are added'];
            $name = self::uniqueName($ctx, NotebookFormat::slug($meta['title']), $ext, (string)$meta['id']);
            $abs = $ctx['root'] . '/' . $area . '/' . $name;
            if (!AtomicWriteService::write($abs, $raw)) return ['error' => self::writeError($abs)];
            $existing[$meta['id']] = true;
            self::$gitCache = [];
            return ['id' => $meta['id']];
        });
        return isset($res['error']) ? (string)$res['error'] : null;
    }

    /** Copy an artefact into another notebook's inbox with meta sharedFrom. Secrets are not copied. */
    public static function share(array $ctx, string $id, string $targetWorkspaceId, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $rec = self::findRecord($ctx, $id);
            if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
            $target = self::contextFor($targetWorkspaceId);
            if (isset($target['error'])) return ['error' => 'The target notebook is not available: ' . $target['error']];
            if ($target['root'] === $ctx['root']) return ['error' => 'The target is the same notebook.'];
            if ($e = self::rateCheck($target, $author)) return ['error' => $e];
            $meta = $rec['meta'];
            $meta['sharedFrom'] = ($ctx['scope'] === 'global' ? 'global' : $ctx['name']) . '#' . $id;
            $newId = '';
            $res = self::withLock($target, function () use ($target, $rec, &$meta, &$newId) {
                if ($e = self::ensureNotebook($target)) return ['error' => $e];
                $recs = self::scan($target);
                if (count($recs) >= self::MAX_ARTEFACTS) return ['error' => 'The target notebook is full.'];
                $used = [];
                foreach ($recs as $r) $used[$r['id']] = true;
                do { $newId = 'a_' . bin2hex(random_bytes(6)); } while (isset($used[$newId]));
                $meta['id'] = $newId;
                $meta['updatedAt'] = NotebookFormat::now();
                $meta['pinned'] = false;
                unset($meta['needsYou'], $meta['secrets']);
                $raw = NotebookFormat::build($meta, $rec['body'], $rec['ext'], $rec['prefix']);
                // The copy has a new "shared from" line, so it can be bigger than the original. scan() skips a
                // file over the limit; refuse it, so the sender does not see "ok" for a copy nobody can see.
                if (strlen($raw) > self::MAX_FILE_BYTES) return ['error' => 'This artefact is too close to the ' . self::limitLabel() . ' limit to be shared. The copy would be larger than ' . self::limitLabel() . '. Make the artefact smaller and try again.'];
                $name = self::uniqueName($target, NotebookFormat::slug($meta['title']), $rec['ext'], $newId);
                $abs = $target['root'] . '/inbox/' . $name;
                if (!AtomicWriteService::write($abs, $raw)) return ['error' => self::writeError($abs)];
                self::$gitCache = [];
                return ['ok' => true];
            });
            if (isset($res['error'])) return $res;
            self::publish($target, $newId, 'created', ['title' => (string)$meta['title'], 'by' => self::byLabel($author), 'area' => 'inbox', 'shared' => true]);
            return ['ok' => true, 'id' => $newId, 'note' => $rec['type'] === 'connection' ? 'The secrets were not copied. Enter them again in the target card.' : ''];
        } catch (\Throwable $e) {
            return ['error' => 'The artefact could not be shared.'];
        }
    }

    /**
     * The text of an artefact (or one checklist item or one section) as it is read aloud, title first.
     * @return array{text:string}|array{error:string}
     */
    private static function readSource(array $ctx, string $id, ?string $ref): array {
        $rec = self::findRecord($ctx, $id);
        if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
        $text = '';
        $ref = trim((string)$ref);
        if ($rec['type'] === 'checklist' && $ref !== '') {
            foreach (NotebookFormat::parseChecklist($rec['body'])['items'] as $it) {
                if ($it['id'] === $ref) { $text = $it['text'] . '.' . ($it['do'] !== '' ? ' ' . $it['do'] : '') . ($it['expect'] !== '' ? ' Expect: ' . $it['expect'] : ''); break; }
            }
        }
        if ($text === '' && $ref !== '' && in_array($rec['type'], ['markdown', 'checklist', 'connection', 'deck'], true)) {
            $text = self::sectionText($rec['body'], $ref);
        }
        if ($text === '') {
            $text = in_array($rec['type'], ['markdown', 'checklist', 'connection', 'deck'], true)
                ? NotebookFormat::plainText($rec['body'])
                : self::summaryText($rec);
        }
        return ['text' => trim($rec['meta']['title'] . '. ' . $text)];
    }

    /** @return array<string,mixed> who speaks: the workspace of this notebook (empty for the global notebook) */
    private static function readActor(array $ctx): array {
        $actor = ['workspaceId' => $ctx['scope'] === 'global' ? '' : $ctx['workspaceId'], 'agentId' => '', 'name' => $ctx['workspaceName'] ?? ''];
        if ($actor['workspaceId'] !== '') {
            $w = self::findWorkspace($actor['workspaceId']);
            if ($w !== null) { $actor['agentId'] = (string)($w['agentId'] ?? ''); if ($actor['name'] === '') $actor['name'] = (string)($w['name'] ?? ''); }
        }
        return $actor;
    }

    /** Test seam: the plugin config the read-aloud plan reads (null = the real one). @var array<string,mixed>|null */
    public static ?array $readConfig = null;
    /** Test seam: replaces the voice engine call of readClip(): fn(string $text, ?array $workspace): array. @var callable|null */
    public static $clipSink = null;
    public const READ_MAX_CHARS = 200000;

    /** @return array<string,mixed> */
    private static function readConfig(): array {
        if (self::$readConfig !== null) return self::$readConfig;
        try { return \AICliAgents\Services\ConfigService::getConfig(); } catch (\Throwable $e) { return []; }
    }

    /**
     * The pieces of an artefact that the page reads aloud one after the other
     * (docs/specs/NOTEBOOK_READ_ALOUD.md R1, R5, R6).
     * @return array{ok:true,chunks:array<int,string>,etag:string,engine:bool}|array{error:string}
     */
    public static function readPlan(array $ctx, string $id, ?string $ref, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $cfg = self::readConfig();
            if ((string)($cfg['voice_enabled'] ?? '0') !== '1') return ['error' => 'Voice is off. Turn it on with the speaker icon in the drawer.', 'reason' => 'global_off'];
            $src = self::readSource($ctx, $id, $ref);
            if (isset($src['error'])) return $src;
            $text = $src['text'];
            if (mb_strlen($text) > self::READ_MAX_CHARS) $text = mb_substr($text, 0, self::READ_MAX_CHARS);
            $chunks = NotebookFormat::speechChunks($text);
            if ($chunks === []) return ['error' => 'There is nothing to read in this artefact.'];
            return ['ok' => true, 'chunks' => $chunks, 'etag' => md5($text), 'engine' => trim((string)($cfg['tts_url'] ?? '')) !== ''];
        } catch (\Throwable $e) {
            return ['error' => 'The text could not be prepared for reading.'];
        }
    }

    /**
     * Make piece $index of a read-aloud plan with the voice engine and return the clip URL.
     * @return array{ok:true,url:string}|array{error:string}
     */
    public static function readClip(array $ctx, string $id, ?string $ref, int $index, string $etag): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $plan = self::readPlan($ctx, $id, $ref, []);
            if (isset($plan['error'])) return $plan;
            if (!hash_equals($plan['etag'], $etag)) return ['error' => 'This note changed while it was being read. Press the speaker again to start over.', 'reason' => 'changed'];
            if ($index < 0 || $index >= count($plan['chunks'])) return ['error' => 'There is no such piece to read.'];
            $actor = self::readActor($ctx);
            $workspace = $actor['workspaceId'] !== '' ? self::findWorkspace((string)$actor['workspaceId']) : null;
            $text = $plan['chunks'][$index];
            if (self::$clipSink !== null) { $r = (self::$clipSink)($text, $workspace); }
            else {
                if (defined('AICLI_TEST_TMP')) return ['error' => 'Test guard: nothing speaks under test.'];
                if (!class_exists('AICliAgents\Services\VoiceService', false)) require_once __DIR__ . '/VoiceService.php';
                $r = VoiceService::readChunkClip($text, $workspace);
            }
            if (($r['mode'] ?? '') !== 'audio' || empty($r['clipId'])) return ['error' => (string)($r['message'] ?? 'The voice engine did not make a clip.'), 'reason' => 'engine'];
            return ['ok' => true, 'url' => '/plugins/unraid-aicliagents/AICliAjax.php?action=voice_clip&id=' . $r['clipId']];
        } catch (\Throwable $e) {
            return ['error' => 'The text could not be read aloud.'];
        }
    }

    /** Speak an artefact (or one item or section) through VoiceService with the workspace voice. One message, so capped. */
    public static function readAloud(array $ctx, string $id, ?string $ref, array $author): array {
        if (isset($ctx['error'])) return $ctx;
        try {
            $src = self::readSource($ctx, $id, $ref);
            if (isset($src['error'])) return $src;
            $text = $src['text'];
            if (mb_strlen($text) > 1500) $text = mb_substr($text, 0, 1500);
            $actor = self::readActor($ctx);
            if (self::$speakSink !== null) { (self::$speakSink)($text, $actor); return ['ok' => true, 'mode' => 'sink']; }
            if (defined('AICLI_TEST_TMP')) return ['error' => 'Test guard: nothing speaks under test.'];
            if (!class_exists('AICliAgents\Services\VoiceService', false)) require_once __DIR__ . '/VoiceService.php';
            $r = VoiceService::speak($text, $actor);
            if (isset($r['error'])) return ['error' => (string)$r['error']];
            return ['ok' => true, 'mode' => (string)($r['mode'] ?? '')];
        } catch (\Throwable $e) {
            return ['error' => 'The text could not be read aloud.'];
        }
    }

    // ======================================================================
    // Core: scan, find, mutate
    // ======================================================================

    /** @return array<int,array<string,mixed>> */
    private static function scan(array $ctx): array {
        $out = [];
        $scanT0 = PerfTrace::start();
        $scanBytes = 0;
        foreach (NotebookFormat::AREAS as $area) {
            $dir = $ctx['root'] . '/' . $area;
            if (!is_dir($dir)) continue;
            $names = @scandir($dir) ?: [];
            sort($names, SORT_STRING);
            foreach ($names as $name) {
                if ($name[0] === '.' || strpos($name, '.tmp.') !== false) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, NotebookFormat::EXTENSIONS, true)) continue;
                $abs = $dir . '/' . $name;
                if (is_link($abs) || !is_file($abs)) continue;
                $size = (int)@filesize($abs);
                if ($size > self::MAX_READ_BYTES) continue;
                $raw = @file_get_contents($abs);
                if ($raw === false) continue;
                $scanBytes += strlen($raw);
                $out[] = self::record($area, $name, $abs, $raw, $ext, (int)@filemtime($abs));
            }
        }
        PerfTrace::point('notebook.scan', $scanT0, 100, 'files=' . count($out) . " bytes=$scanBytes");
        return $out;
    }

    private static function record(string $area, string $name, string $abs, string $raw, string $ext, int $mtime): array {
        $p = NotebookFormat::parse($raw, $ext);
        $meta = $p['meta'];
        $derived = false;
        if (!is_array($meta) || !preg_match('/^a_[0-9a-f]{12}$/D', (string)($meta['id'] ?? ''))) {
            $derived = true;
            $title = NotebookFormat::firstHeading($p['body']) ?: pathinfo($name, PATHINFO_FILENAME);
            $meta = ['id' => 'a_' . substr(sha1($name), 0, 12), 'title' => $title, 'type' => NotebookFormat::typeForExt($ext)];
        }
        $rec = ['area' => $area, 'name' => $name, 'abs' => $abs, 'ext' => $ext, 'mtime' => $mtime];
        $meta = self::normaliseMeta($meta, $rec + ['meta' => []]);
        return [
            'id' => $meta['id'], 'area' => $area, 'name' => $name, 'abs' => $abs, 'rel' => $area . '/' . $name, 'ext' => $ext,
            'meta' => $meta, 'type' => $meta['type'], 'body' => $p['body'], 'prefix' => $p['prefix'], 'raw' => $raw,
            'version' => self::h12($raw), 'size' => strlen($raw), 'mtime' => $mtime, 'derived' => $derived,
        ];
    }

    /** Make meta complete and well-typed. $rec supplies fallbacks (ext, mtime, an existing meta). */
    private static function normaliseMeta(array $meta, array $rec): array {
        $ext = (string)($rec['ext'] ?? 'md');
        $type = (string)($meta['type'] ?? '');
        if (!NotebookFormat::isType($type)) $type = NotebookFormat::typeForExt($ext);
        $fallbackTime = gmdate('Y-m-d\TH:i:s\Z', (int)($rec['mtime'] ?? time()));
        $owner = is_array($meta['owner'] ?? null) ? $meta['owner'] : [];
        $meta['owner'] = [
            'kind' => ($owner['kind'] ?? 'human') === 'agent' ? 'agent' : 'human',
            'agentId' => (string)($owner['agentId'] ?? ''), 'name' => (string)($owner['name'] ?? ''), 'workspace' => (string)($owner['workspace'] ?? ''),
        ];
        $tags = [];
        foreach ((array)($meta['tags'] ?? []) as $t) if (is_scalar($t) && trim((string)$t) !== '') $tags[] = trim((string)$t);
        $meta['tags'] = array_values(array_unique($tags));
        $meta['title'] = trim((string)($meta['title'] ?? '')) !== '' ? (string)$meta['title'] : pathinfo((string)($rec['name'] ?? 'untitled'), PATHINFO_FILENAME);
        $meta['type'] = $type;
        $meta['createdAt'] = (string)($meta['createdAt'] ?? '') !== '' ? (string)$meta['createdAt'] : (string)($rec['meta']['createdAt'] ?? $fallbackTime);
        $meta['updatedAt'] = (string)($meta['updatedAt'] ?? '') !== '' ? (string)$meta['updatedAt'] : $meta['createdAt'];
        $meta['pinned'] = !empty($meta['pinned']);
        if (!empty($meta['needsYou'])) $meta['needsYou'] = true; else unset($meta['needsYou']);
        if (isset($meta['secrets']) && !is_array($meta['secrets'])) unset($meta['secrets']);
        if (!empty($meta['locked'])) { $meta['locked'] = true; $meta['lockedBy'] = mb_substr((string)($meta['lockedBy'] ?? ''), 0, 100); if ($meta['lockedBy'] === '') unset($meta['lockedBy']); }
        else unset($meta['locked'], $meta['lockedBy']);
        if (isset($meta['editedBy']) && !in_array($meta['editedBy'], ['human', 'agent', 'both'], true)) unset($meta['editedBy']);
        return $meta;
    }

    private static function findRecord(array $ctx, string $id): ?array {
        if (!preg_match('/^a_[0-9a-f]{12}$/D', $id) || !is_dir($ctx['root'])) return null;
        $best = null;
        foreach (self::scan($ctx) as $rec) {
            if ($rec['id'] !== $id) continue;
            if ($rec['area'] !== 'archive') return $rec;
            $best = $best ?? $rec;
        }
        return $best;
    }

    private static function isJsonRec(array $rec): bool {
        return NotebookFormat::isJsonType((string)$rec['type']);
    }

    /**
     * The one write path for an existing artefact. $fn(rec) returns ['meta','body','prefix'] or ['error'].
     * Takes the lock, checks the version, builds the candidate, skips an unchanged file, applies size and
     * secret guards, re-reads the hash right before writing, writes atomically, then publishes.
     */
    private static function mutate(array $ctx, string $id, ?string $expected, array $author, string $kind, array $extra, bool $confirm, callable $fn): array {
        try {
            if ($e = self::rateCheck($ctx, $author)) return ['error' => $e];
            if (!preg_match('/^a_[0-9a-f]{12}$/D', $id)) return ['error' => "'$id' is not an artefact id."];
            $res = self::withLock($ctx, function () use ($ctx, $id, $expected, $fn, $confirm, $author) {
                $rec = self::findRecord($ctx, $id);
                if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
                if ($rec['area'] === 'archive') return ['error' => 'This artefact is archived and read-only. Restore it first.'];
                if (self::lockedAgainst($rec, $author)) return self::lockedRefusal();
                if ($expected !== null && $expected !== '' && $expected !== $rec['version']) return self::stale($rec['version']);
                $r = $fn($rec);
                if (isset($r['error'])) return $r;
                $meta = NotebookFormat::orderMeta($r['meta']);
                // Writer-owned keys: only a person locks (a save by an agent cannot add the lock keys),
                // and editedBy is kept as it was until the change below decides otherwise.
                if (($author['kind'] ?? '') === 'agent') unset($meta['locked'], $meta['lockedBy']);
                if (isset($rec['meta']['editedBy'])) $meta['editedBy'] = $rec['meta']['editedBy']; else unset($meta['editedBy']);
                $candidate = NotebookFormat::build($meta, (string)$r['body'], $rec['ext'], (string)($r['prefix'] ?? ''));
                if ($candidate === $rec['raw']) return ['unchanged' => true, 'version' => $rec['version'], 'title' => $rec['meta']['title']];
                if (empty($r['keepEditedBy'])) {
                    $by = ($author['kind'] ?? 'human') === 'agent' ? 'agent' : 'human';
                    $cur = (string)($rec['meta']['editedBy'] ?? ($rec['meta']['owner']['kind'] ?? 'human'));
                    $new = $cur === $by ? $cur : 'both';
                    if ($new !== $cur || isset($rec['meta']['editedBy'])) $meta['editedBy'] = $new;
                }
                $meta['updatedAt'] = NotebookFormat::now();
                $final = NotebookFormat::build($meta, (string)$r['body'], $rec['ext'], (string)($r['prefix'] ?? ''));
                if (strlen($final) > self::MAX_FILE_BYTES) return ['error' => 'This artefact would be larger than ' . self::limitLabel() . ', which is the limit.'];
                if (!$confirm && ($hit = NotebookSecretGuard::addedFinding($rec['raw'], $final)) !== null) return self::secretRefusal($hit);
                $cur = @file_get_contents($rec['abs']);
                if ($cur === false || self::h12($cur) !== $rec['version']) return self::stale($cur === false ? '' : self::h12($cur));
                if (!AtomicWriteService::write($rec['abs'], $final)) return ['error' => self::writeError($rec['abs'])];
                self::$gitCache = [];
                return ['written' => true, 'version' => self::h12($final), 'title' => $meta['title']];
            });
            if (isset($res['error'])) return $res;
            if (!empty($res['written'])) {
                if (($author['kind'] ?? '') !== 'agent') self::markSeen($ctx, $id, (string)$res['version']);
                self::publish($ctx, $id, $kind, $extra + ['title' => (string)$res['title'], 'by' => self::byLabel($author)]);
            }
            $m = self::metaOne($ctx, $id);
            if (isset($m['error'])) return $m;
            $out = ['artefact' => $m];
            if (!empty($res['unchanged'])) $out['unchanged'] = true;
            return $out;
        } catch (\Throwable $e) {
            self::log('mutate failed: ' . $e->getMessage());
            return ['error' => 'The artefact could not be changed.'];
        }
    }

    private static function stale(string $current): array {
        return ['error' => 'This artefact changed since you read it. Read it again and retry.', 'currentVersion' => $current, 'stale' => true];
    }

    private static function secretRefusal(string $hit): array {
        return [
            'error' => "This text looks like $hit. Notebook files are committed to git and must never hold a secret. Remove it, or confirm that it is safe to store.",
            'needsConfirm' => true,
        ];
    }

    private static function writeError(string $abs): string {
        $f = AtomicWriteService::lastFailure();
        $dir = dirname($abs);
        if (!is_writable($dir)) return 'The folder is read-only or not writable, so the file was not saved.';
        $free = @disk_free_space($dir);
        if ($free !== false && $free < 1048576) return 'The disk is full, so the file was not saved.';
        return 'The file could not be written' . ($f ? ' (' . $f['stage'] . ')' : '') . '.';
    }

    private static function metaOne(array $ctx, string $id): array {
        $rec = self::findRecord($ctx, $id);
        if ($rec === null) return ['error' => "There is no artefact with id '$id' in this notebook."];
        return self::toMeta($ctx, $rec, self::makeView($ctx, [$rec]));
    }

    private static function h12(string $raw): string {
        return substr(hash('sha256', $raw), 0, 12);
    }

    /** @return string[]|null */
    private static function cleanTags($tags): ?array {
        if (is_string($tags)) {
            $d = json_decode($tags, true);
            $tags = json_last_error() === JSON_ERROR_NONE ? $d : array_map('trim', explode(',', $tags));
        }
        if ($tags === null || $tags === '') return [];
        if (!is_array($tags)) return null;
        $out = [];
        foreach ($tags as $t) {
            if (!is_scalar($t)) return null;
            $t = trim((string)$t);
            if ($t === '') continue;
            if (mb_strlen($t) > 40) return null;
            $out[] = $t;
        }
        $out = array_values(array_unique($out));
        return count($out) > 20 ? null : $out;
    }

    private static function cleanUrls($urls): array {
        $out = [];
        foreach ((array)$urls as $u) {
            if (!is_array($u)) continue;
            $url = trim((string)($u['url'] ?? ''));
            if ($url === '' || strlen($url) > 500) continue;
            $out[] = ['label' => mb_substr(trim((string)($u['label'] ?? '')), 0, 100), 'url' => $url];
            if (count($out) >= 20) break;
        }
        return $out;
    }

    private static function uniqueName(array $ctx, string $slug, string $ext, string $id): string {
        $name = $slug . '.' . $ext;
        $taken = static function (string $n) use ($ctx): bool {
            foreach (NotebookFormat::AREAS as $a) if (file_exists($ctx['root'] . '/' . $a . '/' . $n)) return true;
            return false;
        };
        $len = 3;
        while ($taken($name)) {
            $name = $slug . '-' . substr($id, 2, $len) . '.' . $ext;
            if (++$len > 12) { $name = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext; break; }
        }
        return $name;
    }

    // ======================================================================
    // Meta view: attention, git, comments
    // ======================================================================

    /** @return array{seen:array,git:array,agentComments:array<string,int>} */
    private static function makeView(array $ctx, array $recs): array {
        return [
            'seen' => self::readState($ctx)['seen'],
            'git' => self::gitInfo($ctx),
            'agentComments' => self::unresolvedAgentComments($ctx),
        ];
    }

    private static function attentionFor(array $rec, array $view): string {
        if ($rec['area'] === 'archive') return 'none';
        $needs = !empty($rec['meta']['needsYou']) || (($view['agentComments'][$rec['id']] ?? 0) > 0);
        if (!$needs && $rec['type'] === 'form') {
            $d = json_decode($rec['body'], true);
            if (is_array($d) && ($d['status'] ?? 'open') === 'open' && !empty($d['questions'])) $needs = true;
        }
        if ($needs) return 'needs-you';
        $seen = $view['seen'][$rec['id']] ?? null;
        if ($seen === null) return 'new';
        return $seen !== $rec['version'] ? 'changed' : 'none';
    }

    private static function toMeta(array $ctx, array $rec, array $view): array {
        $attention = self::attentionFor($rec, $view);
        $m = $rec['meta'];
        $out = [
            'id' => $rec['id'], 'title' => $m['title'], 'type' => $rec['type'], 'area' => $rec['area'], 'path' => $rec['rel'],
            'tags' => $m['tags'], 'owner' => $m['owner'], 'createdAt' => $m['createdAt'], 'updatedAt' => $m['updatedAt'],
            'pinned' => !empty($m['pinned']), 'needsYou' => $attention === 'needs-you', 'attention' => $attention,
            'version' => $rec['version'],
            'committed' => ($view['git']['repo'] ?? false) ? !isset($view['git']['dirty'][$rec['rel']]) : null,
            'summary' => self::summaryText($rec), 'scope' => $ctx['scope'],
        ];
        if ($rec['type'] === 'checklist') $out['progress'] = NotebookFormat::parseChecklist($rec['body'])['counts'];
        if (isset($m['runOf'])) $out['runOf'] = (string)$m['runOf'];
        if (isset($m['sharedFrom'])) $out['sharedFrom'] = (string)$m['sharedFrom'];
        // Wiki layer (contract section 10).
        $out['locked'] = !empty($m['locked']);
        if (!empty($m['locked']) && !empty($m['lockedBy'])) $out['lockedBy'] = (string)$m['lockedBy'];
        $out['editedBy'] = in_array($m['editedBy'] ?? '', ['human', 'agent', 'both'], true) ? (string)$m['editedBy'] : (string)($m['owner']['kind'] ?? 'human');
        if (isset($m['kind']) && is_string($m['kind']) && $m['kind'] !== '') $out['kind'] = $m['kind'];
        $sec = self::sectionOf($rec);
        if ($sec !== '') $out['section'] = $sec;
        return $out;
    }

    private static function summaryText(array $rec): string {
        $body = $rec['body'];
        $text = '';
        switch ($rec['type']) {
            case 'markdown': case 'checklist': case 'connection': case 'deck':
                $text = NotebookFormat::plainText($body);
                $t = trim($rec['meta']['title']);
                if ($t !== '' && stripos($text, $t) === 0) $text = ltrim(substr($text, strlen($t)), " .:-");
                break;
            case 'html':
                $text = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m) ? html_entity_decode(trim($m[1])) : trim(strip_tags($body));
                break;
            case 'svg': $text = ''; break;
            case 'mermaid': case 'log': case 'diff':
                $text = trim(preg_replace('/\s+/', ' ', $body) ?? '');
                break;
            default: {
                $d = json_decode($body, true);
                if (!is_array($d)) break;
                switch ($rec['type']) {
                    case 'table': $text = isset($d['rows']) ? count($d['rows']) . ' rows, ' . count((array)($d['columns'] ?? [])) . ' columns' : 'CSV table'; break;
                    case 'chart': $text = (string)($d['title'] ?? '') ?: ($d['kind'] ?? 'chart') . ' chart'; break;
                    case 'status': $text = implode(', ', array_map(static fn($t) => (string)($t['label'] ?? ''), array_slice((array)($d['tiles'] ?? []), 0, 6))); break;
                    case 'form': $text = (string)($d['intro'] ?? '') ?: (string)($d['questions'][0]['label'] ?? ''); break;
                    case 'board': { $n = 0; foreach ((array)($d['columns'] ?? []) as $c) $n += count((array)($c['cards'] ?? [])); $text = $n . ' cards in ' . count((array)($d['columns'] ?? [])) . ' columns'; break; }
                    case 'walkthrough': $text = count((array)($d['stops'] ?? [])) . ' stops'; break;
                    case 'media': $text = count((array)($d['items'] ?? [])) . ' items'; break;
                    case 'link': $text = (string)($d['url'] ?? ''); break;
                    case 'progress': $text = (string)($d['status'] ?? '') ?: (string)($d['state'] ?? ''); break;
                }
            }
        }
        $text = trim($text);
        return mb_strlen($text) > 140 ? mb_substr($text, 0, 140) : $text;
    }

    private static function madeBy(array $owner, string $q): bool {
        $q = strtolower(trim($q));
        if ($q === 'human' || $q === 'person') return ($owner['kind'] ?? '') === 'human';
        if ($q === 'agent') return ($owner['kind'] ?? '') === 'agent';
        foreach (['agentId', 'name', 'workspace'] as $k) if (strtolower((string)($owner[$k] ?? '')) === $q) return true;
        return false;
    }

    private static function snippet(string $body, array $words): string {
        $plain = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if ($plain === '') return '';
        $pos = false;
        foreach ($words as $w) {
            $p = stripos($plain, $w);
            if ($p !== false) { $pos = $p; break; }
        }
        if ($pos === false) return mb_substr($plain, 0, 140);
        $start = max(0, $pos - 50);
        return ($start > 0 ? '...' : '') . mb_substr($plain, $start, 140) . (strlen($plain) > $start + 140 ? '...' : '');
    }

    private static function sectionText(string $body, string $heading): string {
        $want = strtolower(trim($heading, " \t#"));
        $lines = explode("\n", $body);
        $out = [];
        $level = 0;
        $inFence = false;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if (preg_match('/^\s*```/', $line)) $inFence = !$inFence;
            if (!$inFence && preg_match('/\A(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*\z/', $line, $h)) {
                if ($level === 0 && strtolower(trim($h[2])) === $want) { $level = strlen($h[1]); continue; }
                if ($level > 0 && strlen($h[1]) <= $level) break;
            }
            if ($level > 0) $out[] = $line;
        }
        return NotebookFormat::plainText(implode("\n", $out));
    }

    // ======================================================================
    // Comments storage
    // ======================================================================

    private static function commentsPath(array $ctx, string $id): string {
        return $ctx['root'] . '/.comments/' . $id . '.json';
    }

    /** @return array{schema:int,comments:array} */
    private static function readComments(array $ctx, string $id): array {
        $f = self::commentsPath($ctx, $id);
        $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        $list = is_array($d) && is_array($d['comments'] ?? null) ? array_values($d['comments']) : [];
        return ['schema' => 1, 'comments' => $list];
    }

    private static function countUnresolved(array $ctx, string $id): int {
        $n = 0;
        foreach (self::readComments($ctx, $id)['comments'] as $c) if (empty($c['resolved'])) $n++;
        return $n;
    }

    /** @return array<string,int> artefact id => number of unresolved comments written by an agent */
    private static function unresolvedAgentComments(array $ctx): array {
        $dir = $ctx['root'] . '/.comments';
        $out = [];
        if (!is_dir($dir)) return $out;
        foreach (@scandir($dir) ?: [] as $f) {
            if (!preg_match('/^(a_[0-9a-f]{12})\.json$/D', $f, $m)) continue;
            $d = json_decode((string)@file_get_contents($dir . '/' . $f), true);
            foreach ((array)($d['comments'] ?? []) as $c) {
                if (empty($c['resolved']) && ($c['by']['kind'] ?? '') === 'agent') $out[$m[1]] = ($out[$m[1]] ?? 0) + 1;
            }
        }
        return $out;
    }

    // ======================================================================
    // Notebook folder, marker, lock, state, rate limit
    // ======================================================================

    private static function readMarker(string $root): array {
        $f = $root . '/.notebook.json';
        if (!is_file($f)) return [];
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || !preg_match('/^nb_[0-9a-f]{12}$/D', (string)($d['id'] ?? ''))) return [];
        return $d;
    }

    /** Create the folder, the four areas and .notebook.json on the first write. @return ?string error */
    /**
     * Test-mode guard. Outside PHPUnit this is always false. Under PHPUnit (AICLI_TEST_TMP is defined) a path is a
     * violation unless it sits inside the test temp folder or the system temp folder, and never inside the live
     * plugin state at /tmp/unraid-aicliagents. The nearest existing ancestor is resolved with realpath so a symlink
     * cannot lead out.
     */
    public static function unsafeInTest(string $path): bool {
        if (!defined('AICLI_TEST_TMP')) return false;
        // The live plugin state is forbidden by name, whether or not the folder exists yet.
        $norm = '/' . trim(preg_replace('#/+#', '/', $path), '/');
        // The live state root is named by the constant, not the sandbox root: a test may not use it.
        $liveRoot = SecretPaths::LIVE_STATE_ROOT;
        if ($norm === $liveRoot || strncmp($norm, $liveRoot . '/', strlen($liveRoot) + 1) === 0) return true;
        $probe = $path;
        while ($probe !== '' && $probe !== '/' && !file_exists($probe)) $probe = dirname($probe);
        $real = realpath($probe === '' ? '/' : $probe);
        if ($real === false) return true;
        $real = rtrim($real, '/') . '/';
        $live = realpath(SecretPaths::LIVE_STATE_ROOT);
        if ($live !== false && strncmp($real, rtrim($live, '/') . '/', strlen(rtrim($live, '/')) + 1) === 0) return true;
        foreach ([(string)AICLI_TEST_TMP, sys_get_temp_dir()] as $ok) {
            $okReal = realpath($ok);
            if ($okReal !== false && strncmp($real, rtrim($okReal, '/') . '/', strlen(rtrim($okReal, '/')) + 1) === 0) return false;
        }
        return true;
    }

    private static function ensureNotebook(array $ctx): ?string {
        if (self::unsafeInTest((string)$ctx['root'])) return 'Test guard: this notebook folder is outside the test temp area.';
        $root = $ctx['root'];
        if (!is_dir($root)) {
            $parent = dirname($root);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) return 'The project folder could not be created. It may be read-only or full.';
            if (!is_writable($parent)) return 'The project folder is read-only, so the notebook cannot be written.';
            if (!@mkdir($root, 0755, true) && !is_dir($root)) return 'The notebook folder could not be created. The project folder may be read-only or full.';
        } elseif (!is_writable($root)) {
            return 'The notebook folder is read-only, so nothing can be written.';
        }
        foreach (NotebookFormat::AREAS as $a) {
            $d = $root . '/' . $a;
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) return "The folder $a could not be created.";
        }
        if (!is_file($root . '/.notebook.json')) {
            $marker = ['schema' => 1, 'id' => $ctx['notebookId'], 'name' => $ctx['name'], 'created' => NotebookFormat::now()];
            if (!AtomicWriteService::writeJson($root . '/.notebook.json', $marker)) return 'The notebook marker could not be written.';
        }
        return null;
    }

    private static function stateDir(): string {
        return self::$stateDirOverride !== null ? rtrim(self::$stateDirOverride, '/') : self::stateDirDefault();
    }

    /** Non-blocking flock, polled to a deadline. */
    private static function withLock(array $ctx, callable $fn): array {
        $dir = self::stateDir();
        if (self::unsafeInTest((string)$ctx['root']) || self::unsafeInTest($dir)) return ['error' => 'Test guard: this notebook is outside the test temp area.'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['error' => 'The notebook lock folder could not be created.'];
        $fh = @fopen($dir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$ctx['notebookId']) . '.lock', 'c');
        if ($fh === false) return ['error' => 'The notebook lock could not be opened.'];
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        $got = false;
        $lockT0 = PerfTrace::start();
        while (true) {
            if (flock($fh, LOCK_EX | LOCK_NB)) { $got = true; break; }
            if (microtime(true) >= $deadline) break;
            usleep(25000);
        }
        $lockNb = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$ctx['notebookId']);
        if (!$got) {
            fclose($fh);
            PerfTrace::record('notebook.lock', PerfTrace::elapsedMs($lockT0), "nb=$lockNb result=timeout");
            return ['error' => 'The notebook is busy with another change. Try again in a moment.'];
        }
        PerfTrace::point('notebook.lock', $lockT0, 250, "nb=$lockNb result=got");
        try {
            $r = $fn();
            return is_array($r) ? $r : ['error' => 'Internal error.'];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array{seen:array<string,string>} */
    private static function readState(array $ctx): array {
        $nb = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$ctx['notebookId']);
        $files = [self::stateDir() . "/$nb.json"];
        if (self::$stateDirOverride === null) $files[] = self::stateDirFlash() . "/$nb.json";
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d) && is_array($d['seen'] ?? null)) return ['seen' => array_map('strval', $d['seen'])];
        }
        return ['seen' => []];
    }

    private static function markSeen(array $ctx, string $id, string $version): void {
        try {
            if ($id === '') return;
            $state = self::readState($ctx);
            if (($state['seen'][$id] ?? null) === $version) return;
            $state['seen'][$id] = $version;
            $nb = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$ctx['notebookId']);
            $dir = self::stateDir();
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            AtomicWriteService::writeJson("$dir/$nb.json", $state);
            if (self::$stateDirOverride === null) {
                // Durable mirror on flash, at most every 5 minutes (flash wear).
                $flash = self::stateDirFlash() . "/$nb.json";
                if (!is_file($flash) || (time() - (int)@filemtime($flash)) > 300) {
                    if (!is_dir(self::stateDirFlash())) @mkdir(self::stateDirFlash(), 0755, true);
                    AtomicWriteService::writeJson($flash, $state);
                }
            }
        } catch (\Throwable $e) {
            // Read state is a convenience; never fail a write because of it.
        }
    }

    /** 60 changes a minute per notebook, agents only. @return ?string refusal */
    private static function rateCheck(array $ctx, array $author): ?string {
        if (($author['kind'] ?? '') !== 'agent') return null;
        try {
            $dir = self::stateDir();
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $f = $dir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$ctx['notebookId']) . '.rate.json';
            $now = time();
            $list = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
            $list = array_values(array_filter((array)$list, static fn($t) => is_int($t) && $t > $now - 60));
            if (count($list) >= self::RATE_PER_MINUTE) return 'Too many changes in one minute (the limit is ' . self::RATE_PER_MINUTE . ' for each notebook). Wait a moment and try again.';
            $list[] = $now;
            @file_put_contents($f, json_encode($list), LOCK_EX);
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    // ======================================================================
    // Vault (connection-card secrets)
    // ======================================================================

    /** Vault key in SecretService's UPPER_SNAKE shape. */
    private static function vaultKey(string $artefactId, string $field): string {
        return 'NB_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($artefactId, 2))) . '_' . strtoupper($field);
    }

    /** The reference written in the file. The value is only a name, never the secret. */
    private static function vaultRef(array $ctx, string $artefactId, string $field): string {
        return 'vault:nb-' . $ctx['notebookId'] . '-' . $artefactId . '-' . $field;
    }

    /** @return array<string,string> */
    private static function vaultLoad(array $ctx): array {
        if (self::$vaultStub !== null) return self::$vaultStub[$ctx['notebookId']] ?? [];
        if (defined('AICLI_TEST_TMP')) return []; // never the real vault under test
        return SecretService::getWorkspaceSecrets('notebook:' . $ctx['notebookId'], 'notebook');
    }

    private static function vaultSave(array $ctx, array $map): bool {
        if (self::$vaultStub !== null) {
            if ($map === []) unset(self::$vaultStub[$ctx['notebookId']]); else self::$vaultStub[$ctx['notebookId']] = $map;
            return true;
        }
        if (defined('AICLI_TEST_TMP')) return false; // never the real vault under test
        return SecretService::saveWorkspaceSecrets('notebook:' . $ctx['notebookId'], 'notebook', $map);
    }

    /** @return ?string error */
    private static function vaultSet(array $ctx, string $artefactId, string $field, string $value): ?string {
        if ($ctx['scope'] === 'global') return 'A global card cannot hold a secret. Use a project notebook.';
        if ($value === '' || strlen($value) > 4096) return 'A secret must be 1 to 4096 characters.';
        if (preg_match('/[\r\n\0]/', $value)) return 'A secret cannot contain a line break.';
        if ($ctx['settings']['allowProdSecrets'] !== '1' && NotebookSecretGuard::isProductionKey($value)) {
            return 'This value looks like a live production key. Production keys are blocked unless "allow production secrets" is turned on in the plugin settings.';
        }
        $key = self::vaultKey($artefactId, $field);
        $map = self::vaultLoad($ctx);
        $before = $map;
        $map[$key] = $value;
        if (!self::vaultSave($ctx, $map)) return 'The vault could not be written, so the secret was not stored.';
        $check = self::vaultLoad($ctx);
        if (($check[$key] ?? null) !== $value) {
            self::vaultSave($ctx, $before);
            return 'The vault cannot store this value exactly (it has characters the vault format cannot hold). Nothing was stored.';
        }
        return null;
    }

    /** @return ?string error */
    private static function vaultClear(array $ctx, string $artefactId, string $field): ?string {
        $key = self::vaultKey($artefactId, $field);
        $map = self::vaultLoad($ctx);
        if (!isset($map[$key])) return null;
        unset($map[$key]);
        return self::vaultSave($ctx, $map) ? null : 'The vault could not be written.';
    }

    // ======================================================================
    // Git (read only, 3 second deadline, GIT_OPTIONAL_LOCKS=0)
    // ======================================================================

    /** @return array{repo:bool,dirty:array<string,bool>} paths relative to the notebook root */
    private static function gitInfo(array $ctx): array {
        $none = ['repo' => false, 'dirty' => []];
        $root = $ctx['root'];
        if ($ctx['scope'] === 'global' || !is_dir($root)) return $none;
        $c = self::$gitCache[$root] ?? null;
        if ($c !== null && (microtime(true) - $c[0]) < 0.5 && $c[1] !== null) return $c[1];
        $top = self::git($root, ['rev-parse', '--is-inside-work-tree', '--show-prefix']);
        if ($top === null || $top[0] !== 0) { self::$gitCache[$root] = [microtime(true), $none]; return $none; }
        $lines = explode("\n", $top[1]);
        if (trim($lines[0] ?? '') !== 'true') { self::$gitCache[$root] = [microtime(true), $none]; return $none; }
        $prefix = trim($lines[1] ?? '');
        $st = self::git($root, ['status', '--porcelain', '-z', '-uall', '--', '.'], 3000, 4000000);
        if ($st === null || $st[0] !== 0) return ['repo' => true, 'dirty' => []]; // repo, but status unknown: do not cache
        $dirty = [];
        $parts = explode("\0", $st[1]);
        for ($i = 0; $i < count($parts); $i++) {
            $e = $parts[$i];
            if (strlen($e) < 4) continue;
            $xy = substr($e, 0, 2);
            $path = substr($e, 3);
            if ($xy[0] === 'R' || $xy[0] === 'C') $i++; // the next entry is the old name
            if ($prefix !== '' && strncmp($path, $prefix, strlen($prefix)) === 0) $path = substr($path, strlen($prefix));
            $dirty[$path] = true;
        }
        $res = ['repo' => true, 'dirty' => $dirty];
        self::$gitCache[$root] = [microtime(true), $res];
        return $res;
    }

    /**
     * Run git with an array argv (no shell), a hard deadline and a size cap.
     * @return array{0:int,1:string}|null [exit code, stdout] or null on timeout or failure to start
     */
    private static function git(string $dir, array $args, int $timeoutMs = 3000, int $maxBytes = 262144): ?array {
        $gitT0 = PerfTrace::start();
        $gitVerb = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($args[0] ?? '')));
        $cmd = array_merge(['git', '-c', 'safe.directory=*', '-C', $dir], $args);
        $env = array_merge(getenv() ?: [], ['GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C', 'GIT_PAGER' => 'cat']);
        if (empty($env['HOME'])) $env['HOME'] = '/root';
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) { PerfTrace::point('notebook.git', $gitT0, 300, "verb=$gitVerb rc=start-failed"); return null; }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $timeoutMs / 1000;
        $timedOut = false;
        while (true) {
            $left = $deadline - microtime(true);
            if ($left <= 0) { $timedOut = true; break; }
            $r = [];
            if (!feof($pipes[1])) $r[] = $pipes[1];
            if (!feof($pipes[2])) $r[] = $pipes[2];
            if ($r === []) break;
            $w = null; $x = null;
            @stream_select($r, $w, $x, 0, (int)min(200000, $left * 1000000));
            foreach ($r as $s) {
                $chunk = fread($s, 65536);
                if ($s === $pipes[1] && is_string($chunk) && $chunk !== '') {
                    $out .= $chunk;
                    if (strlen($out) > $maxBytes) { $out = substr($out, 0, $maxBytes); break 2; }
                }
            }
        }
        if ($timedOut) {
            @proc_terminate($proc, 9);
            foreach ($pipes as $p) @fclose($p);
            @proc_close($proc);
            PerfTrace::record('notebook.git', PerfTrace::elapsedMs($gitT0), "verb=$gitVerb rc=timeout");
            return null;
        }
        foreach ($pipes as $p) @fclose($p);
        $rc = proc_close($proc);
        PerfTrace::point('notebook.git', $gitT0, 300, "verb=$gitVerb rc=$rc");
        return [$rc, $out];
    }

    // ======================================================================
    // Workspaces, settings, events, logging
    // ======================================================================

    /**
     * The projects the Wiki page can show (docs/specs/NOTEBOOK_WIKI_PAGE.md R2, R4). The Notebook API reaches a
     * project through a workspace id, so each project is found from the workspace records: the records are grouped
     * by project folder, and each group gives one entry. A stopped workspace still counts, because its record stays
     * in the registry. LIMIT: a project whose workspaces are all deleted is not listed. A project is listed only
     * when its notebook folder exists. Global is always the last entry.
     * @return list<array{key:string,label:string,workspaceId:string,scope:string,workspaces:list<string>}>
     */
    public static function wikiProjects(): array {
        $settings = self::settings();
        if ($settings['enabled'] !== '1') return [];
        $sessions = self::$workspacesOverride;
        if ($sessions === null) {
            $sessions = [];
            try {
                if (!class_exists('AICliAgents\Services\ConfigService', false)) {
                    $f = __DIR__ . '/ConfigService.php';
                    if (is_file($f)) require_once $f;
                }
                if (class_exists('AICliAgents\Services\ConfigService', false)) {
                    $sessions = (array)(ConfigService::getWorkspaces()['sessions'] ?? []);
                }
            } catch (\Throwable $e) {
                self::log('wiki projects lookup failed: ' . $e->getMessage());
            }
        }
        $groups = [];
        foreach ($sessions as $s) {
            if (!is_array($s)) continue;
            $id = (string)($s['id'] ?? '');
            if ($id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $id)) continue;
            $path = rtrim((string)($s['path'] ?? ''), '/');
            if ($path === '' || !is_dir($path)) continue;
            $project = PoolPathService::resolvePoolPath($path) ?? $path;
            if (!is_dir($project . '/' . $settings['folder'])) continue;
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') $name = $id;
            if (!isset($groups[$project])) $groups[$project] = ['workspaceId' => $id, 'names' => []];
            if (!in_array($name, $groups[$project]['names'], true)) $groups[$project]['names'][] = $name;
        }
        $out = [];
        foreach ($groups as $project => $g) {
            $out[] = [
                'key' => 'p_' . substr(sha1((string)$project), 0, 12),
                'label' => implode(', ', $g['names']),
                'workspaceId' => $g['workspaceId'],
                'scope' => 'notebook',
                'workspaces' => $g['names'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        $out[] = ['key' => 'global', 'label' => 'Global', 'workspaceId' => 'global', 'scope' => 'global', 'workspaces' => []];
        return $out;
    }

    /**
     * The workspace record for an id, or null. A fresh cache entry answers first, so a poll does not
     * read the whole registry (R3). Any cache failure falls back to the registry; the answer is the same.
     * @return array<string,mixed>|null
     */
    private static function findWorkspace(string $id): ?array {
        self::$lastLookupWasCacheHit = false;
        $cached = self::wsCacheRead($id);
        if ($cached !== null) {
            self::$lastLookupWasCacheHit = true;
            return $cached;
        }
        try {
            if (self::$registryLookup !== null) {
                $sessions = (array)(self::$registryLookup)();
            } else {
                if (!class_exists('AICliAgents\Services\ConfigService', false)) {
                    $f = __DIR__ . '/ConfigService.php';
                    if (is_file($f)) require_once $f;
                }
                if (!class_exists('AICliAgents\Services\ConfigService', false)) return null;
                $sessions = (array)(ConfigService::getWorkspaces()['sessions'] ?? []);
            }
            foreach ($sessions as $s) {
                if (is_array($s) && (string)($s['id'] ?? '') === $id) {
                    self::wsCacheStore($id, $s);
                    return $s;
                }
            }
        } catch (\Throwable $e) {
            self::log('workspace lookup failed: ' . $e->getMessage());
        }
        return null;
    }

    /** The cache file, or null when the cache is off (under PHPUnit without setWorkspaceCachePath(), or an unsafe test path). */
    private static function wsCachePath(): ?string {
        if (self::$wsCachePathOverride !== null) {
            $p = self::$wsCachePathOverride;
            if ($p === '' || self::unsafeInTest(dirname($p))) return null;
            return $p;
        }
        if (defined('AICLI_TEST_TMP')) return null;
        return self::wsCacheFile();
    }

    /** @return array<string,mixed> the cache content: id => [name, path, at, rec]; empty on any problem */
    private static function wsCacheLoad(string $path): array {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** A fresh cache record for $id whose folder still exists, or null. Never throws. @return array<string,mixed>|null */
    private static function wsCacheRead(string $id): ?array {
        try {
            $file = self::wsCachePath();
            if ($file === null) return null;
            $e = self::wsCacheLoad($file)[$id] ?? null;
            if (!is_array($e) || !is_array($e['rec'] ?? null) || !is_string($e['path'] ?? null)) return null;
            if (!is_int($e['at'] ?? null) && !is_float($e['at'] ?? null)) return null;
            $age = microtime(true) - (float)$e['at'];
            if ($age < 0 || $age >= self::WS_CACHE_TTL) return null;
            if ((string)($e['rec']['id'] ?? '') !== $id) return null;
            $dir = rtrim($e['path'], '/');
            if ($dir === '' || !is_dir($dir)) return null;
            return $e['rec'];
        } catch (\Throwable $t) {
            return null;
        }
    }

    /** Write one entry. A temp file and a rename, so a reader sees one whole file. Never throws, never changes the answer. */
    private static function wsCacheStore(string $id, array $rec): void {
        try {
            $file = self::wsCachePath();
            if ($file === null) return;
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
            $now = microtime(true);
            $all = [];
            foreach (self::wsCacheLoad($file) as $k => $e) {
                if (!is_array($e) || !is_numeric($e['at'] ?? null)) continue;
                $age = $now - (float)$e['at'];
                if ($age >= 0 && $age < self::WS_CACHE_TTL) $all[(string)$k] = $e;
            }
            $all[$id] = ['name' => (string)($rec['name'] ?? ''), 'path' => (string)($rec['path'] ?? ''), 'at' => $now, 'rec' => $rec];
            if (count($all) > self::WS_CACHE_MAX_ENTRIES) $all = array_slice($all, -self::WS_CACHE_MAX_ENTRIES, null, true);
            $json = json_encode($all);
            if (!is_string($json)) return;
            $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return; }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) @unlink($tmp);
        } catch (\Throwable $t) {
            // A cache failure never changes the result.
        }
    }

    /** @return array{enabled:string,autoOpen:string,folder:string,allowProdSecrets:string,spokenNotice:string,journal:string,quietHours:string} */
    private static function settings(): array {
        $c = [];
        try {
            if (!class_exists('AICliAgents\Services\ConfigService', false)) {
                $f = __DIR__ . '/ConfigService.php';
                if (is_file($f)) require_once $f;
            }
            if (class_exists('AICliAgents\Services\ConfigService', false)) $c = (array)ConfigService::getConfig();
        } catch (\Throwable $e) {
            $c = [];
        }
        $folder = (string)($c['notebook_folder'] ?? 'notebook');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/D', $folder) || strpos($folder, '..') !== false) $folder = 'notebook';
        $quiet = trim((string)($c['notebook_quiet_hours'] ?? ''));
        if (!preg_match(self::QUIET_RE, $quiet)) $quiet = '';
        $flag = static fn(string $k, string $d): string => (string)($c[$k] ?? $d) === '1' ? '1' : (isset($c[$k]) && (string)$c[$k] !== '' ? '0' : $d);
        return [
            'enabled' => $flag('notebook_enabled', '1'), 'autoOpen' => $flag('notebook_auto_open', '0'), 'folder' => $folder,
            'allowProdSecrets' => $flag('notebook_allow_prod_secrets', '0'), 'spokenNotice' => $flag('notebook_spoken_notice', '0'),
            'journal' => $flag('notebook_journal', '0'), 'quietHours' => $quiet,
        ];
    }

    /**
     * True when the server's local time is inside $spec, "HH:MM-HH:MM". The window may wrap midnight
     * (22:00-07:00). Empty or invalid text means no quiet hours. A window whose two times are equal is empty.
     */
    public static function inQuietHours(string $spec, ?int $now = null): bool {
        $spec = trim($spec);
        if ($spec === '' || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/D', $spec, $m)) return false;
        $a = (int)$m[1] * 60 + (int)$m[2];
        $b = (int)$m[3] * 60 + (int)$m[4];
        if ($a === $b) return false;
        $t = $now ?? time();
        $n = (int)date('G', $t) * 60 + (int)date('i', $t);
        return $a < $b ? ($n >= $a && $n < $b) : ($n >= $a || $n < $b);
    }

    /** One short spoken line, "I put <title> in your notebook." Best effort; the voice switches and limits still apply. */
    private static function speakNotice(string $title, array $author): void {
        try {
            if (self::inQuietHours(self::settings()['quietHours'])) return; // Settings > Notebook > Quiet hours
            if (self::$speakSink !== null) { (self::$speakSink)($title, $author); return; }
            if (defined('AICLI_TEST_TMP')) return; // nothing speaks under test
            if (!class_exists('AICliAgents\Services\VoiceService', false)) require_once __DIR__ . '/VoiceService.php';
            $t = mb_strlen($title) > 80 ? mb_substr($title, 0, 80) : $title;
            VoiceService::speak("I put \"$t\" in your notebook.", [
                'workspaceId' => (string)($author['workspaceId'] ?? ''),
                'agentId'     => (string)($author['agentId'] ?? ''),
                'name'        => (string)($author['workspace'] ?? $author['name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /** Publish on the `notebook` channel. Never carries a secret value or file content. */
    private static function publish(array $ctx, string $id, string $kind, array $extra = []): void {
        try {
            $payload = ['workspaceId' => (string)$ctx['workspaceId'], 'notebookId' => (string)$ctx['notebookId'], 'id' => $id, 'kind' => $kind] + $extra;
            if (self::$eventSink !== null) { (self::$eventSink)($payload); return; }
            EventBus::publish('notebook', [], $payload);
        } catch (\Throwable $e) {
            // Best effort, like every other publisher.
        }
    }

    private static function log(string $message): void {
        if (function_exists('aicli_log')) {
            aicli_log($message, defined('AICLI_LOG_WARN') ? AICLI_LOG_WARN : 1, 'NotebookService');
        }
    }

    private static function magicOk(string $ext, string $b): bool {
        switch ($ext) {
            case 'png':  return strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0;
            case 'jpg': case 'jpeg': return strncmp($b, "\xFF\xD8\xFF", 3) === 0;
            case 'gif':  return strncmp($b, 'GIF8', 4) === 0;
            case 'webp': return strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP';
            case 'pdf':  return strncmp($b, '%PDF-', 5) === 0;
            case 'zip':  return strncmp($b, "PK\x03\x04", 4) === 0;
            case 'svg':  return stripos($b, '<svg') !== false;
        }
        return true;
    }

    /** Small, safe Markdown to HTML for the html export. Everything is escaped first. */
    private static function markdownToHtml(string $md): string {
        $lines = explode("\n", str_replace("\r\n", "\n", $md));
        $out = '';
        /** @var string $list the open list tag ('ul', 'ol' or ''), changed inside the closures below */
        $list = '';
        /** @var array<int,string> $para the lines of the open paragraph, changed inside the closures below */
        $para = [];
        $inCode = false;
        $code = [];
        /** @var array<int,string> $table the lines of the open table, changed inside the closures below */
        $table = [];
        $inline = static function (string $s): string {
            $s = htmlspecialchars($s, ENT_QUOTES);
            $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
            $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
            $s = preg_replace('/(?<![*\w])\*([^*]+)\*(?![*\w])/', '<em>$1</em>', $s) ?? $s;
            $s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $s) ?? $s;
            return $s;
        };
        $flushPara = function () use (&$para, &$out, $inline) { if ($para) { $out .= '<p>' . $inline(implode(' ', $para)) . "</p>\n"; $para = []; } };
        $flushList = function () use (&$list, &$out) { if ($list !== '') { $out .= "</$list>\n"; $list = ''; } };
        $flushTable = function () use (&$table, &$out, $inline) {
            if (!$table) return;
            $out .= "<table>\n";
            foreach ($table as $i => $row) {
                if (preg_match('/^\|?[\s:\-|]+\|?$/', $row) && $i === 1) continue;
                $cells = array_map('trim', explode('|', trim($row, '| ')));
                $tag = $i === 0 ? 'th' : 'td';
                $out .= '<tr>' . implode('', array_map(static fn($c) => "<$tag>" . $inline($c) . "</$tag>", $cells)) . "</tr>\n";
            }
            $out .= "</table>\n";
            $table = [];
        };
        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if ($inCode) { $out .= '<pre>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES) . "</pre>\n"; $code = []; $inCode = false; }
                else { $flushPara(); $flushList(); $flushTable(); $inCode = true; }
                continue;
            }
            if ($inCode) { $code[] = $line; continue; }
            if (preg_match('/^\|.*\|\s*$/', $line)) { $flushPara(); $flushList(); $table[] = $line; continue; }
            $flushTable();
            if (trim($line) === '') { $flushPara(); $flushList(); continue; }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) { $flushPara(); $flushList(); $n = min(6, strlen($m[1]) + 1); $out .= "<h$n>" . $inline(preg_replace('/\s*\{#[^}]*\}\s*$/', '', $m[2])) . "</h$n>\n"; continue; }
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) { $flushPara(); $flushList(); $out .= '<blockquote>' . $inline($m[1]) . "</blockquote>\n"; continue; }
            if (preg_match('/^\s*[-*+]\s+(?:\[([ xX])\]\s+)?(.*)$/', $line, $m)) {
                $flushPara();
                if ($list !== 'ul') { $flushList(); $out .= "<ul>\n"; $list = 'ul'; }
                $box = $m[1] === '' ? '' : ($m[1] === ' ' ? '&#9744; ' : '&#9745; ');
                $out .= '<li>' . $box . $inline(preg_replace('/\s*\{#[^}]*\}\s*$/', '', $m[2])) . "</li>\n";
                continue;
            }
            if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
                $flushPara();
                if ($list !== 'ol') { $flushList(); $out .= "<ol>\n"; $list = 'ol'; }
                $out .= '<li>' . $inline($m[1]) . "</li>\n";
                continue;
            }
            if (preg_match('/^\s{2,}\S/', $line) && $list !== '') { $out = preg_replace('#</li>\n$#', '<br>' . $inline(trim($line)) . "</li>\n", $out) ?? $out; continue; }
            if (preg_match('/^(-{3,}|\*{3,})\s*$/', $line)) { $flushPara(); $flushList(); $out .= "<hr>\n"; continue; }
            $para[] = trim($line);
        }
        if ($inCode) $out .= '<pre>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES) . "</pre>\n";
        $flushPara(); $flushList(); $flushTable();
        return $out;
    }
}
