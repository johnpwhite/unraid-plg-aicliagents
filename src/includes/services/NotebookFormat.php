<?php
/**
 * <module_context>
 *     <name>NotebookFormat</name>
 *     <description>Pure text work for the Notebook (docs/specs/ARTEFACTS_CONTRACT.md section 2):
 *     split a file into meta + body and join them again, per extension (front matter for md,
 *     comment block for html and svg, first-line comment for mmd, diff and log, `_meta` key for
 *     json); the checklist grammar (find items, set one item's result with minimal line edits,
 *     number items); section replace for Markdown; the JSON-type patch operations (form answer,
 *     board cards, progress); the type table and starter content for each type.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>No file I/O, no globals, no throwing on bad input (a function returns an
 *     error string or null). Output is STABLE: build(parse(x)) == x for any file this class
 *     wrote, and the same input always gives the same bytes, so a diff shows only real changes.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class NotebookFormat {

    /** type => [extension, kind]. kind 'text' = body is text; 'json' = body is the JSON object without `_meta`. */
    public const TYPES = [
        'markdown'    => ['md',   'text'],
        'checklist'   => ['md',   'text'],
        'connection'  => ['md',   'text'],
        'deck'        => ['md',   'text'],
        'mermaid'     => ['mmd',  'text'],
        'svg'         => ['svg',  'text'],
        'html'        => ['html', 'text'],
        'log'         => ['log',  'text'],
        'diff'        => ['diff', 'text'],
        'table'       => ['json', 'json'],
        'chart'       => ['json', 'json'],
        'status'      => ['json', 'json'],
        'json'        => ['json', 'json'],
        'form'        => ['json', 'json'],
        'board'       => ['json', 'json'],
        'walkthrough' => ['json', 'json'],
        'media'       => ['json', 'json'],
        'link'        => ['json', 'json'],
        'progress'    => ['json', 'json'],
    ];

    public const AREAS = ['inbox', 'working', 'notes', 'archive'];
    public const EXTENSIONS = ['md', 'mmd', 'html', 'svg', 'json', 'log', 'diff'];
    public const RESULTS = ['pending', 'pass', 'fail', 'blocked', 'skipped', 'done'];
    public const SECRET_FIELDS = ['password', 'token', 'apiKey'];

    /** Meta keys in the order a writer emits them; everything else follows alphabetically. */
    private const META_ORDER = ['id', 'title', 'type', 'tags', 'owner', 'createdAt', 'updatedAt', 'pinned'];

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public static function isType(string $type): bool { return isset(self::TYPES[$type]); }
    public static function extFor(string $type): string { return self::TYPES[$type][0] ?? 'md'; }
    public static function isJsonType(string $type): bool { return (self::TYPES[$type][1] ?? '') === 'json'; }

    /** Best guess at a type for a file that carries no meta. */
    public static function typeForExt(string $ext): string {
        switch ($ext) {
            case 'mmd':  return 'mermaid';
            case 'svg':  return 'svg';
            case 'html': return 'html';
            case 'log':  return 'log';
            case 'diff': return 'diff';
            case 'json': return 'json';
            default:     return 'markdown';
        }
    }

    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    // ------------------------------------------------------------------
    // Meta: parse and build
    // ------------------------------------------------------------------

    /**
     * Split $raw into meta and body.
     * @return array{meta:?array,body:string,prefix:string}  meta null = no (valid) meta block.
     */
    public static function parse(string $raw, string $ext): array {
        $bom = '';
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) { $bom = "\xEF\xBB\xBF"; $raw = substr($raw, 3); }
        $none = ['meta' => null, 'body' => $bom . $raw, 'prefix' => ''];
        switch ($ext) {
            case 'md':
                return self::parseFrontMatter($raw) ?? $none;
            case 'html':
            case 'svg': {
                $prefix = '';
                $rest = $raw;
                if ($ext === 'svg' && preg_match('/\A<\?xml[^>]*\?>[ \t]*\r?\n?/', $rest, $m)) {
                    $prefix = $m[0];
                    $rest = substr($rest, strlen($prefix));
                }
                if (!preg_match('/\A<!--[ \t]*notebook-meta[ \t]*\r?\n(.*?)\r?\n-->/s', $rest, $m)) return $none;
                $meta = json_decode($m[1], true);
                if (!is_array($meta)) return $none;
                // Writer emits "-->\n\n". Consume exactly that (or a single newline), nothing more.
                $tail = substr($rest, strlen($m[0]));
                if (preg_match('/\A\r?\n\r?\n/', $tail, $n1) || preg_match('/\A\r?\n/', $tail, $n1)) $tail = substr($tail, strlen($n1[0]));
                return ['meta' => $meta, 'body' => $tail, 'prefix' => $prefix];
            }
            case 'mmd':
            case 'diff':
            case 'log': {
                $marker = $ext === 'mmd' ? '%% notebook-meta ' : '# notebook-meta ';
                if (strncmp($raw, $marker, strlen($marker)) !== 0) return $none;
                $nl = strpos($raw, "\n");
                $line = $nl === false ? substr($raw, strlen($marker)) : substr($raw, strlen($marker), $nl - strlen($marker));
                $meta = json_decode(rtrim($line, "\r"), true);
                if (!is_array($meta)) return $none;
                return ['meta' => $meta, 'body' => $nl === false ? '' : substr($raw, $nl + 1), 'prefix' => ''];
            }
            case 'json': {
                $obj = json_decode($raw, false);
                if (!($obj instanceof \stdClass) || !isset($obj->_meta) || !($obj->_meta instanceof \stdClass)) return $none;
                $meta = json_decode(json_encode($obj->_meta), true);
                unset($obj->_meta);
                return ['meta' => is_array($meta) ? $meta : null, 'body' => self::encodeJson($obj), 'prefix' => ''];
            }
        }
        return $none;
    }

    /** Join meta + body into file text. Inverse of parse(). */
    public static function build(array $meta, string $body, string $ext, string $prefix = ''): string {
        $meta = self::orderMeta($meta);
        switch ($ext) {
            case 'md': {
                $out = "---\n";
                foreach ($meta as $k => $v) $out .= $k . ': ' . self::encodeValue($v) . "\n";
                return $out . "---\n" . $body;
            }
            case 'html':
            case 'svg':
                return $prefix . "<!-- notebook-meta\n" . self::encodeJson(self::metaForJson($meta)) . "\n-->\n\n" . $body;
            case 'mmd':
                return '%% notebook-meta ' . json_encode(self::metaForJson($meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $body;
            case 'diff':
            case 'log':
                return '# notebook-meta ' . json_encode(self::metaForJson($meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $body;
            case 'json': {
                $data = json_decode($body === '' ? '{}' : $body, false);
                $out = new \stdClass();
                $out->_meta = self::metaForJson($meta);
                if ($data instanceof \stdClass) {
                    foreach (get_object_vars($data) as $k => $v) $out->$k = $v;
                }
                return self::encodeJson($out) . "\n";
            }
        }
        return $body;
    }

    public static function encodeJson($v): string {
        $s = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $s === false ? '{}' : $s;
    }

    /** Meta as an object-safe value: empty owner and secrets stay objects. */
    private static function metaForJson(array $meta) {
        $o = new \stdClass();
        foreach ($meta as $k => $v) {
            if (($k === 'owner' || $k === 'secrets') && is_array($v) && $v === []) $v = new \stdClass();
            $o->$k = $v;
        }
        return $o;
    }

    /** Stable key order: the fixed list first, then the rest alphabetically. Drops null and empty needsYou/secrets. */
    public static function orderMeta(array $meta): array {
        if (array_key_exists('needsYou', $meta) && !$meta['needsYou']) unset($meta['needsYou']);
        if (array_key_exists('secrets', $meta) && (!is_array($meta['secrets']) || $meta['secrets'] === [])) unset($meta['secrets']);
        $out = [];
        foreach (self::META_ORDER as $k) {
            if (array_key_exists($k, $meta)) $out[$k] = $meta[$k];
        }
        $rest = array_diff_key($meta, $out);
        ksort($rest, SORT_STRING);
        return $out + $rest;
    }

    /** Front matter: `---` first line, `key: value` lines, closing `---`. */
    private static function parseFrontMatter(string $raw): ?array {
        if (!preg_match('/\A---[ \t]*\r?\n/', $raw, $open)) return null;
        $pos = strlen($open[0]);
        $meta = [];
        $len = strlen($raw);
        while ($pos <= $len) {
            $nl = strpos($raw, "\n", $pos);
            $line = $nl === false ? substr($raw, $pos) : substr($raw, $pos, $nl - $pos);
            $next = $nl === false ? $len + 1 : $nl + 1;
            $line = rtrim($line, "\r");
            if (preg_match('/\A---[ \t]*\z/', $line)) {
                return ['meta' => $meta, 'body' => $next > $len ? '' : substr($raw, $next), 'prefix' => ''];
            }
            if ($nl === false && $line === '') return null;
            if (preg_match('/\A([A-Za-z0-9_.\-]+):[ \t]*(.*)\z/', $line, $m)) {
                $meta[$m[1]] = self::decodeValue($m[2]);
            }
            if ($nl === false) return null; // never found the closing line
            $pos = $next;
        }
        return null;
    }

    public static function decodeValue(string $v) {
        $t = trim($v);
        if ($t === '') return '';
        $d = json_decode($t, true);
        if (json_last_error() === JSON_ERROR_NONE) return $d;
        return $t;
    }

    /** Bare string when safe, else JSON. */
    public static function encodeValue($v): string {
        if (is_string($v)) {
            $safe = $v !== ''
                && $v === trim($v)
                && !preg_match('/[\r\n\x00-\x08\x0b\x0c\x0e-\x1f]/', $v)
                && strpos($v, ': ') === false
                && !in_array($v[0], ['[', '{', '"'], true);
            if ($safe) {
                json_decode($v);
                if (json_last_error() !== JSON_ERROR_NONE) return $v;
            }
        }
        $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $s === false ? '""' : $s;
    }

    // ------------------------------------------------------------------
    // Names and text helpers
    // ------------------------------------------------------------------

    public static function slug(string $title, int $max = 60): string {
        $s = strtolower(trim($title));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = strtolower($t);
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), '-');
        return $s !== '' ? $s : 'untitled';
    }

    /** Plain text of a Markdown body for summaries and speech. */
    public static function plainText(string $md): string {
        $t = preg_replace('/```.*?```/s', ' ', $md) ?? $md;
        $t = preg_replace('/\{#[A-Za-z0-9_\-]+\}/', '', $t) ?? $t;
        $t = preg_replace('/^[ \t]*(?:#{1,6}[ \t]+|>[ \t]?(?:\[![A-Z]+\][ \t]*)?|[-*+][ \t]+(?:\[[ xX]\][ \t]+)?|\d+\.[ \t]+)/m', '', $t) ?? $t;
        $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t;
        $t = preg_replace('/\[\[([^\]]+)\]\]/', '$1', $t) ?? $t;
        $t = preg_replace('/[*_`~]+/', '', $t) ?? $t;
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    public static function firstHeading(string $md): string {
        if (preg_match('/^#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$/m', $md, $m)) return trim($m[1]);
        return '';
    }

    // ------------------------------------------------------------------
    // Starter content
    // ------------------------------------------------------------------

    /** Default body (text types) or default object (json types, as a PHP array) for an empty create. */
    public static function skeleton(string $type, string $title) {
        switch ($type) {
            case 'markdown':   return '# ' . $title . "\n\n";
            case 'checklist':  return '# ' . $title . "\n\n- [ ] First step {#s1}\n  - Do:\n  - Expect:\n  - Result: pending\n";
            case 'connection': return "# " . $title . "\n\nWhat this connection is for, and anything to know before using it.\n";
            case 'deck':       return '# ' . $title . "\n\nFirst slide\n---\n## Second slide\n\nNote: speaker note\n";
            case 'mermaid':    return "flowchart TD\n    A[Start] --> B[End]\n";
            case 'svg':        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><rect width="200" height="100" fill="none" stroke="currentColor"/></svg>' . "\n";
            case 'html':       return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>" . htmlspecialchars($title, ENT_QUOTES) . "</title></head>\n<body>\n<h1>" . htmlspecialchars($title, ENT_QUOTES) . "</h1>\n</body></html>\n";
            case 'log':
            case 'diff':       return '';
            case 'table':      return ['columns' => [], 'rows' => []];
            case 'chart':      return ['kind' => 'bar', 'title' => $title, 'xLabel' => '', 'yLabel' => '', 'labels' => [], 'series' => []];
            case 'status':     return ['tiles' => []];
            case 'json':       return ['data' => null];
            case 'form':       return ['intro' => '', 'deadline' => '', 'status' => 'open', 'questions' => [], 'answers' => new \stdClass()];
            case 'board':      return ['columns' => []];
            case 'walkthrough':return ['stops' => []];
            case 'media':      return ['items' => []];
            case 'link':       return ['url' => '', 'title' => $title, 'checkedAt' => '', 'status' => ''];
            case 'progress':   return ['state' => 'running', 'percent' => 0, 'status' => '', 'steps' => [], 'tiles' => [], 'log' => []];
        }
        return '';
    }

    /**
     * Check and complete the content of a json type. $obj is the decoded object (assoc array).
     * @return array{0:?array,1:?string} [normalised array, error]
     */
    public static function normaliseJsonContent(string $type, $obj): array {
        if (!is_array($obj) || (array_keys($obj) === range(0, count($obj) - 1) && $obj !== [])) {
            return [null, "A $type artefact needs a JSON object."];
        }
        unset($obj['_meta']);
        $skel = self::skeleton($type, '');
        if (!is_array($skel)) return [null, 'Unknown type.'];
        if ($type === 'table' && isset($obj['csv'])) {
            unset($skel['columns'], $skel['rows']);
            $skel = ['csv' => ''];
        }
        $out = [];
        foreach ($skel as $k => $default) {
            $out[$k] = array_key_exists($k, $obj) ? $obj[$k] : $default;
        }
        foreach ($obj as $k => $v) if (!array_key_exists($k, $out)) $out[$k] = $v;

        switch ($type) {
            case 'form': {
                if (!is_array($out['questions'])) return [null, 'A form needs a questions list.'];
                $seen = [];
                $n = 0;
                foreach ($out['questions'] as $i => $q) {
                    if (!is_array($q)) return [null, 'Each form question must be an object.'];
                    $n++;
                    $qid = (string)($q['id'] ?? '');
                    if ($qid === '') $qid = 'q' . $n;
                    if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/D', $qid)) return [null, "Question id '$qid' is not valid."];
                    if (isset($seen[$qid])) return [null, "Question id '$qid' is used twice."];
                    $seen[$qid] = true;
                    $kind = (string)($q['kind'] ?? 'text');
                    if (!in_array($kind, ['choice', 'multi', 'yesno', 'approve', 'text', 'number', 'date'], true)) return [null, "Question '$qid' has an unknown kind '$kind'."];
                    if (($kind === 'choice' || $kind === 'multi') && (empty($q['options']) || !is_array($q['options']))) return [null, "Question '$qid' needs options."];
                    $q = ['id' => $qid, 'kind' => $kind] + $q;
                    $q['id'] = $qid;
                    $q['kind'] = $kind;
                    $out['questions'][$i] = $q;
                }
                $out['questions'] = array_values($out['questions']);
                if (!in_array($out['status'], ['open', 'answered'], true)) $out['status'] = 'open';
                if (!is_array($out['answers']) && !($out['answers'] instanceof \stdClass)) $out['answers'] = new \stdClass();
                break;
            }
            case 'board': {
                if (!is_array($out['columns'])) return [null, 'A board needs a columns list.'];
                foreach ($out['columns'] as $ci => $col) {
                    if (!is_array($col)) return [null, 'Each board column must be an object.'];
                    if (empty($col['id'])) $col['id'] = 'col' . ($ci + 1);
                    if (!isset($col['cards']) || !is_array($col['cards'])) $col['cards'] = [];
                    foreach ($col['cards'] as $ki => $card) {
                        if (!is_array($card)) return [null, 'Each board card must be an object.'];
                        if (empty($card['id'])) $card['id'] = 'c_' . bin2hex(random_bytes(3));
                        $col['cards'][$ki] = $card;
                    }
                    $out['columns'][$ci] = $col;
                }
                break;
            }
            case 'walkthrough': {
                if (!is_array($out['stops'])) return [null, 'A walkthrough needs a stops list.'];
                foreach ($out['stops'] as $i => $s) {
                    if (!is_array($s)) return [null, 'Each stop must be an object.'];
                    if (empty($s['id'])) $s['id'] = 'st' . ($i + 1);
                    $out['stops'][$i] = $s;
                }
                break;
            }
            case 'progress': {
                if (!in_array($out['state'], ['running', 'done', 'failed'], true)) $out['state'] = 'running';
                $out['percent'] = max(0, min(100, (int)$out['percent']));
                break;
            }
            case 'chart': {
                if (!in_array($out['kind'], ['line', 'bar', 'area', 'pie', 'scatter', 'heatmap', 'timeline'], true)) return [null, 'Unknown chart kind.'];
                break;
            }
            case 'link': {
                if ($out['url'] !== '' && !preg_match('#^https?://#i', (string)$out['url'])) return [null, 'A link artefact needs an http or https address.'];
                break;
            }
        }
        return [$out, null];
    }

    // ------------------------------------------------------------------
    // Checklist grammar
    // ------------------------------------------------------------------

    private const ITEM_RE = '/\A- \[( |x|X)\] (.*?)(?:[ \t]*\{#([A-Za-z0-9_\-]+)\})?[ \t]*\z/';
    private const FIELD_RE = '/\A([ \t]+)- ([A-Za-z][A-Za-z ]{0,30}):[ \t]?(.*)\z/';

    /**
     * Give every item a stable id and reject duplicates.
     * @return array{0:string,1:?string} [body, error]
     */
    public static function numberItems(string $body): array {
        $lines = explode("\n", $body);
        $used = [];
        $max = 0;
        $inFence = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) { $inFence = !$inFence; continue; }
            if ($inFence) continue;
            if (preg_match(self::ITEM_RE, rtrim($line, "\r"), $m) && ($m[3] ?? '') !== '') {
                if (isset($used[$m[3]])) return [$body, "Step id '{$m[3]}' is used twice."];
                $used[$m[3]] = true;
                if (preg_match('/^s(\d+)$/D', $m[3], $n)) $max = max($max, (int)$n[1]);
            }
        }
        $inFence = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*```/', $line)) { $inFence = !$inFence; continue; }
            if ($inFence) continue;
            $cr = substr($line, -1) === "\r" ? "\r" : '';
            if (preg_match(self::ITEM_RE, rtrim($line, "\r"), $m) && ($m[3] ?? '') === '') {
                do { $max++; $id = 's' . $max; } while (isset($used[$id]));
                $used[$id] = true;
                $lines[$i] = rtrim(rtrim($line, "\r")) . ' {#' . $id . '}' . $cr;
            }
        }
        return [implode("\n", $lines), null];
    }

    /**
     * Parse a checklist body.
     * @return array{items:array<int,array>,counts:array<string,int>}
     *   item = [id,text,group,result,by,at,note,checked,start,end]  (start/end = line index range of the item block)
     */
    public static function parseChecklist(string $body): array {
        $lines = explode("\n", $body);
        $items = [];
        $group = '';
        $inFence = false;
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i], "\r");
            if (preg_match('/^\s*```/', $line)) { $inFence = !$inFence; continue; }
            if ($inFence) continue;
            if (preg_match('/\A#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*\z/', $line, $h)) { $group = trim($h[1]); continue; }
            if (!preg_match(self::ITEM_RE, $line, $m)) continue;
            $start = $i;
            $end = $i;
            for ($j = $i + 1; $j < $n; $j++) {
                $l = rtrim($lines[$j], "\r");
                if ($l === '') continue;
                if ($l[0] === ' ' || $l[0] === "\t") { $end = $j; continue; }
                break;
            }
            $fields = [];
            $last = null;
            for ($j = $start + 1; $j <= $end; $j++) {
                $l = rtrim($lines[$j], "\r");
                if (preg_match(self::FIELD_RE, $l, $f)) {
                    $last = strtolower(trim($f[2]));
                    $fields[$last] = $f[3];
                } elseif ($last !== null && trim($l) !== '') {
                    $fields[$last] .= "\n" . trim($l);
                }
            }
            $checked = strtolower($m[1]) === 'x';
            $result = strtolower(trim((string)($fields['result'] ?? '')));
            if (!in_array($result, self::RESULTS, true)) $result = $checked ? 'pass' : 'pending';
            if ($result === 'pending' && $checked) $result = 'pass';
            if ($result === 'done') $result = 'pass';
            $items[] = [
                'id' => (string)($m[3] ?? ''), 'text' => trim($m[2]), 'group' => $group,
                'result' => $result, 'by' => trim((string)($fields['by'] ?? '')), 'at' => trim((string)($fields['at'] ?? '')),
                'note' => trim((string)($fields['note'] ?? '')), 'checked' => $checked, 'start' => $start, 'end' => $end,
                'do' => trim((string)($fields['do'] ?? '')), 'expect' => trim((string)($fields['expect'] ?? '')),
            ];
            $i = $end;
        }
        /** @var array<string,int> $counts */
        $counts = ['total' => count($items), 'pass' => 0, 'fail' => 0, 'blocked' => 0, 'skipped' => 0, 'pending' => 0];
        foreach ($items as $it) $counts[$it['result']] = ($counts[$it['result']] ?? 0) + 1;
        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * Set the result of one checklist item with the smallest possible text change.
     * @return array{0:?string,1:?string} [new body, error]
     */
    public static function setItemState(string $body, string $itemId, string $result, ?string $note, string $by, string $at): array {
        $result = strtolower(trim($result));
        if (!in_array($result, self::RESULTS, true)) return [null, "Result must be one of: pending, pass, fail, blocked, skipped, done."];
        $parsed = self::parseChecklist($body);
        $target = null;
        foreach ($parsed['items'] as $it) if ($it['id'] === $itemId) { $target = $it; break; }
        if ($target === null) return [null, "This checklist has no step with id '$itemId'."];

        $nl = strpos($body, "\r\n") !== false ? "\r\n" : "\n";
        $lines = explode("\n", $body);
        foreach ($lines as $i => $l) $lines[$i] = rtrim($l, "\r");

        $mark = ($result === 'pass' || $result === 'done') ? 'x' : ' ';
        $lines[$target['start']] = preg_replace('/\A- \[[ xX]\]/', "- [$mark]", $lines[$target['start']]);

        // Locate existing field lines (key => [startLine, endLine]) inside the block, and the indent in use.
        $indent = '  ';
        $fieldAt = [];
        $lastKey = null;
        for ($j = $target['start'] + 1; $j <= $target['end']; $j++) {
            if (preg_match(self::FIELD_RE, $lines[$j], $f)) {
                $indent = $f[1];
                $lastKey = strtolower(trim($f[2]));
                if (!isset($fieldAt[$lastKey])) $fieldAt[$lastKey] = [$j, $j];
                else $lastKey = null;
            } elseif ($lastKey !== null && isset($fieldAt[$lastKey]) && trim($lines[$j]) !== '') {
                $fieldAt[$lastKey][1] = $j;
            }
        }
        $set = ['result' => ['Result', $result], 'by' => ['By', $by], 'at' => ['At', $at]];
        if ($note !== null) $set['note'] = ['Note', $note];

        // Apply bottom-up so line numbers stay valid.
        $ops = [];
        $append = [];
        foreach ($set as $key => [$label, $value]) {
            $text = str_replace(["\r\n", "\r"], "\n", $value);
            $new = [$indent . '- ' . $label . ':' . ($text === '' ? '' : ' ' . explode("\n", $text)[0])];
            foreach (array_slice(explode("\n", $text), 1) as $cont) $new[] = $indent . '  ' . $cont;
            if (isset($fieldAt[$key])) $ops[] = [$fieldAt[$key][0], $fieldAt[$key][1], $new];
            else $append = array_merge($append, $new);
        }
        usort($ops, static fn($a, $b) => $b[0] <=> $a[0]);
        $end = $target['end'];
        foreach ($ops as [$s, $e, $new]) {
            array_splice($lines, $s, $e - $s + 1, $new);
            $end += count($new) - ($e - $s + 1);
        }
        // Skip trailing blank lines of the block when appending.
        $insertAt = $end + 1;
        while ($insertAt - 1 > $target['start'] && trim($lines[$insertAt - 1]) === '') $insertAt--;
        if ($append) array_splice($lines, $insertAt, 0, $append);
        return [implode($nl, $lines), null];
    }

    /** A fresh run: every Result back to pending, boxes unticked, By/At removed, Note emptied. */
    public static function resetChecklist(string $body): string {
        $nl = strpos($body, "\r\n") !== false ? "\r\n" : "\n";
        $lines = array_map(static fn($l) => rtrim($l, "\r"), explode("\n", $body));
        $out = [];
        $inItem = false;
        $skipCont = false;
        foreach ($lines as $line) {
            if (preg_match(self::ITEM_RE, $line)) {
                $inItem = true; $skipCont = false;
                $out[] = preg_replace('/\A- \[[ xX]\]/', '- [ ]', $line);
                continue;
            }
            if ($inItem && preg_match(self::FIELD_RE, $line, $f)) {
                $key = strtolower(trim($f[2])); $skipCont = false;
                if ($key === 'result') { $out[] = $f[1] . '- Result: pending'; continue; }
                if ($key === 'by' || $key === 'at') continue;
                if ($key === 'note') { $out[] = $f[1] . '- Note:'; $skipCont = true; continue; }
                $out[] = $line; continue;
            }
            if ($inItem && $skipCont && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) continue;
            if ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t") { $inItem = false; $skipCont = false; }
            $out[] = $line;
        }
        return implode($nl, $out);
    }

    // ------------------------------------------------------------------
    // Markdown sections
    // ------------------------------------------------------------------

    /**
     * Replace the text under a heading. A missing heading is appended as a new "## heading" section.
     * @return string new body
     */
    public static function replaceSection(string $body, string $heading, string $text): string {
        $wantLevel = 0;
        $want = trim($heading);
        if (preg_match('/\A(#{1,6})[ \t]+(.*)\z/', $want, $m)) { $wantLevel = strlen($m[1]); $want = trim($m[2]); }
        $want = strtolower(trim($want, " \t#"));
        $nl = strpos($body, "\r\n") !== false ? "\r\n" : "\n";
        $lines = explode("\n", $body);
        $inFence = false;
        $start = -1; $level = 0;
        foreach ($lines as $i => $raw) {
            $line = rtrim($raw, "\r");
            if (preg_match('/^\s*```/', $line)) { $inFence = !$inFence; continue; }
            if ($inFence) continue;
            if (preg_match('/\A(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*\z/', $line, $h)) {
                if ($start < 0) {
                    if (strtolower(trim($h[2])) === $want && ($wantLevel === 0 || $wantLevel === strlen($h[1]))) { $start = $i; $level = strlen($h[1]); }
                } elseif (strlen($h[1]) <= $level) {
                    $end = $i;
                    return self::spliceSection($lines, $start, $end, $text, $nl);
                }
            }
        }
        if ($start >= 0) return self::spliceSection($lines, $start, count($lines), $text, $nl);
        $trim = rtrim($body, "\r\n");
        return ($trim === '' ? '' : $trim . $nl . $nl) . '## ' . trim($heading, " \t#") . $nl . $nl . trim($text, "\r\n") . $nl;
    }

    private static function spliceSection(array $lines, int $start, int $end, string $text, string $nl): string {
        $new = array_merge([$lines[$start], ''], explode("\n", str_replace("\r\n", "\n", trim($text, "\r\n"))), $end < count($lines) ? [''] : []);
        $before = array_slice($lines, 0, $start);
        $after = array_slice($lines, $end);
        $out = array_map(static fn($l) => rtrim($l, "\r"), array_merge($before, $new, $after));
        $joined = implode($nl, $out);
        if ($end >= count($lines) && substr($joined, -1) !== "\n") $joined .= $nl;
        return $joined;
    }

    // ------------------------------------------------------------------
    // JSON-type operations (operate on the decoded object as an assoc array)
    // ------------------------------------------------------------------

    /** @return ?string error */
    public static function answerForm(array &$data, string $qid, $value, ?string $note, string $byKind, string $at): ?string {
        $q = null;
        foreach (($data['questions'] ?? []) as $cand) if (is_array($cand) && ($cand['id'] ?? '') === $qid) { $q = $cand; break; }
        if ($q === null) return "This form has no question with id '$qid'.";
        $kind = (string)($q['kind'] ?? 'text');
        $opts = [];
        foreach (($q['options'] ?? []) as $o) $opts[] = is_array($o) ? (string)($o['value'] ?? '') : (string)$o;
        switch ($kind) {
            case 'choice':
                if (!is_scalar($value) || !in_array((string)$value, $opts, true)) return "Choose one of: " . implode(', ', $opts) . '.';
                $value = (string)$value; break;
            case 'multi':
                if (!is_array($value)) return 'Send a list of chosen options.';
                $value = array_values(array_map('strval', $value));
                foreach ($value as $v) if (!in_array($v, $opts, true)) return "'$v' is not one of the options.";
                break;
            case 'yesno':
                if (is_string($value)) { $l = strtolower($value); if (in_array($l, ['yes', 'true', '1'], true)) $value = true; elseif (in_array($l, ['no', 'false', '0'], true)) $value = false; }
                if (!is_bool($value)) return 'Answer yes or no.';
                break;
            case 'approve':
                if (is_string($value)) { $l = strtolower($value); if (in_array($l, ['approve', 'approved', 'yes', 'true'], true)) $value = true; elseif (in_array($l, ['reject', 'rejected', 'no', 'false'], true)) $value = false; }
                if (!is_bool($value)) return 'Answer approve or reject.';
                break;
            case 'number':
                if (!is_numeric($value)) return 'Answer with a number.';
                $value = $value + 0; break;
            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))) return 'Answer with a date as YYYY-MM-DD.';
                break;
            default:
                if (!is_scalar($value)) return 'Answer with text.';
                $value = (string)$value;
                if (strlen($value) > 4000) return 'The answer is longer than 4000 characters.';
        }
        $answers = $data['answers'] ?? [];
        if ($answers instanceof \stdClass) $answers = get_object_vars($answers);
        if (!is_array($answers)) $answers = [];
        $answers[$qid] = ['value' => $value, 'by' => $byKind === 'agent' ? 'agent' : 'human', 'at' => $at, 'note' => (string)$note];
        $data['answers'] = $answers;
        $allDone = true;
        foreach (($data['questions'] ?? []) as $cand) {
            if (!empty($cand['required']) && !array_key_exists((string)($cand['id'] ?? ''), $answers)) { $allDone = false; break; }
        }
        // A form with no required question is answered as soon as one question has an answer.
        $data['status'] = $allDone ? 'answered' : 'open';
        return null;
    }

    /** @return ?string error */
    public static function moveCard(array &$data, string $cardId, string $toColumn, int $index): ?string {
        $hasTarget = false;
        foreach (($data['columns'] ?? []) as $col) if (($col['id'] ?? '') === $toColumn) { $hasTarget = true; break; }
        $card = null;
        foreach (($data['columns'] ?? []) as $ci => $col) {
            foreach (($col['cards'] ?? []) as $ki => $c) {
                if (!$hasTarget) break 2;
                if (($c['id'] ?? '') === $cardId) { $card = $c; array_splice($data['columns'][$ci]['cards'], $ki, 1); break 2; }
            }
        }
        if (!$hasTarget) return "This board has no column with id '$toColumn'.";
        if ($card === null) return "This board has no card with id '$cardId'.";
        foreach ($data['columns'] as $ci => $col) {
            if (($col['id'] ?? '') === $toColumn) {
                $cards = $col['cards'] ?? [];
                $index = max(0, min(count($cards), $index));
                array_splice($cards, $index, 0, [$card]);
                $data['columns'][$ci]['cards'] = $cards;
                return null;
            }
        }
        return "This board has no column with id '$toColumn'.";
    }

    /** @return ?string error */
    public static function addCard(array &$data, string $columnId, array $card): ?string {
        if (trim((string)($card['title'] ?? '')) === '') return 'A card needs a title.';
        if (empty($card['id'])) $card['id'] = 'c_' . bin2hex(random_bytes(3));
        foreach (($data['columns'] ?? []) as $ci => $col) {
            foreach (($col['cards'] ?? []) as $c) if (($c['id'] ?? '') === $card['id']) return "Card id '{$card['id']}' is already used.";
        }
        foreach (($data['columns'] ?? []) as $ci => $col) {
            if (($col['id'] ?? '') === $columnId) {
                $data['columns'][$ci]['cards'][] = $card;
                return null;
            }
        }
        return "This board has no column with id '$columnId'.";
    }

    /** @return ?string error */
    public static function setProgress(array &$data, array $op): ?string {
        if (isset($op['state'])) {
            if (!in_array($op['state'], ['running', 'done', 'failed'], true)) return 'state must be running, done or failed.';
            $data['state'] = $op['state'];
        }
        if (isset($op['percent'])) {
            if (!is_numeric($op['percent'])) return 'percent must be a number.';
            $data['percent'] = max(0, min(100, (int)$op['percent']));
        }
        if (isset($op['status'])) $data['status'] = (string)$op['status'];
        if (isset($op['steps'])) {
            if (!is_array($op['steps'])) return 'steps must be a list.';
            $steps = [];
            foreach ($op['steps'] as $s) {
                if (!is_array($s) || !isset($s['label'])) return 'Each step needs a label.';
                $state = (string)($s['state'] ?? 'todo');
                if (!in_array($state, ['todo', 'doing', 'done', 'failed'], true)) return "Step state '$state' is not valid.";
                $steps[] = ['label' => (string)$s['label'], 'state' => $state];
            }
            $data['steps'] = $steps;
        }
        if (isset($op['tiles'])) {
            if (!is_array($op['tiles'])) return 'tiles must be a list.';
            $data['tiles'] = array_values($op['tiles']);
        }
        if (isset($op['appendLog'])) {
            $add = is_array($op['appendLog']) ? $op['appendLog'] : explode("\n", (string)$op['appendLog']);
            $log = is_array($data['log'] ?? null) ? $data['log'] : [];
            foreach ($add as $l) $log[] = (string)$l;
            $data['log'] = array_slice($log, -500);
        }
        return null;
    }

    /** Keep empty maps as objects when the array is re-encoded. */
    public static function fixMaps(array $data): array {
        if (array_key_exists('answers', $data) && $data['answers'] === []) $data['answers'] = new \stdClass();
        return $data;
    }
    /**
     * Cut text into pieces that are read aloud one after the other (docs/specs/NOTEBOOK_READ_ALOUD.md R1).
     * A piece ends at a sentence end or a line end. A sentence longer than $max is cut at a comma or
     * a space, and a word longer than $max is cut hard. Short sentences are joined up to $max.
     * @return array<int,string> never an empty piece
     */
    public static function speechChunks(string $text, int $max = 380): array {
        $max = max(40, $max);
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') return [];
        $sentences = [];
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            $line = trim((string)preg_replace('/[ \t]+/', ' ', $line));
            if ($line === '') continue;
            $parts = preg_split('/(?<=[.!?])\s+/u', $line) ?: [$line];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') continue;
                while (mb_strlen($part) > $max) {
                    $cut = mb_substr($part, 0, $max);
                    $pos = max((int)mb_strrpos($cut, ', '), (int)mb_strrpos($cut, '; '), (int)mb_strrpos($cut, ': '));
                    if ($pos < (int)($max / 3)) $pos = (int)mb_strrpos($cut, ' ');
                    if ($pos < (int)($max / 3)) $pos = $max - 1;
                    $sentences[] = trim(mb_substr($part, 0, $pos + 1));
                    $part = trim(mb_substr($part, $pos + 1));
                }
                if ($part !== '') $sentences[] = $part;
            }
        }
        $out = [];
        $cur = '';
        foreach ($sentences as $sn) {
            if ($sn === '') continue;
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($sn) > $max) { $out[] = $cur; $cur = ''; }
            $cur = $cur === '' ? $sn : $cur . ' ' . $sn;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }
}
