<?php
/**
 * <module_context>
 *     <name>FavouritesService</name>
 *     <description>Bookmarks one workspace's identity — its agent, folder, name and
 *     voice switch — so the operator can bring it back after the workspace is closed
 *     completely (docs/specs/WORKSPACE_FAVOURITES.md R1). A favourite is a pointer to
 *     an (agentId, path) pair, not a copy of that workspace's files: launch args, env
 *     overrides, secrets and the resume id already survive a close on their own,
 *     because they are keyed by (path, agentId), not by favourite. Two favourites can
 *     never share the same (agentId, path) — saving over an existing pair updates it
 *     in place instead of adding a second entry.</description>
 *     <dependencies>ConfigService (the workspace registry `addFromWorkspace` reads),
 *     AtomicWriteService (temp+rename write), LogService (the corrupt-file warning
 *     HealthService's `favourites` check watches for).</dependencies>
 *     <constraints>The favourites file lives on Flash
 *     (/boot/config/plugins/unraid-aicliagents/favourites.json); self::$path
 *     overrides it for tests, so a test never writes to /boot. The read-modify-write
 *     cycle in add()/update()/remove()/touch() is guarded by a short LOCK_EX|LOCK_NB
 *     retry loop on a lock file that lives on TMPFS (self::$lockPath overrides it) —
 *     never a blocking flock, and never a lock file on Flash. A favourites file that
 *     is not valid JSON, or has no `favourites` array, is renamed aside to
 *     `favourites.json.corrupt-<epoch>` and treated as empty; it is never deleted and
 *     never blocks a later write.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

require_once __DIR__ . '/AtomicWriteService.php';

final class FavouritesService {

    public const SCHEMA_VERSION = 1;

    /** Real path on Flash. self::$path overrides it for tests. */
    public const REAL_PATH = '/boot/config/plugins/unraid-aicliagents/favourites.json';

    /** Real lock path on TMPFS. self::$lockPath overrides it for tests. */
    public const REAL_LOCK_PATH = '/tmp/unraid-aicliagents/favourites.lock';

    /** Minted id shape: 'f' + this many [a-z0-9] characters. */
    private const ID_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const ID_LEN = 5;

    /**
     * S8 (REVIEW_2026-09-13_EVENTS_AND_SECURITY.md#S8): the favourites file
     * has no other cap, so a runaway caller (a scripted loop, a broken UI
     * retry) could grow it without bound. `add()` refuses a NEW (agentId,
     * path) pair once the list already holds this many — updating an
     * existing pair is never refused, since that does not grow the list.
     */
    public const MAX_FAVOURITES = 200;

    /** Lock retry budget — TMPFS LOCK_NB, never a blocking flock. */
    private const LOCK_RETRIES = 10;

    /** Delay between lock retries, in microseconds (10 * 20ms = 200ms ceiling). */
    private const LOCK_RETRY_DELAY_US = 20000;

    /** Test seam: overrides the favourites.json path. Null uses REAL_PATH. */
    public static ?string $path = null;

    /** Test seam: overrides the coordination lock path. Null uses REAL_LOCK_PATH. */
    public static ?string $lockPath = null;

    /** Resolved favourites.json path (REAL_PATH unless a test set $path). */
    public static function path(): string {
        return self::$path ?? self::REAL_PATH;
    }

    /** Resolved lock path (REAL_LOCK_PATH unless a test set $lockPath). */
    public static function lockPath(): string {
        return self::$lockPath ?? self::REAL_LOCK_PATH;
    }

    /**
     * True when a `favourites.json.corrupt-*` sibling exists next to the
     * favourites file — the fact HealthService's `favourites` check reports.
     * A cheap glob; safe to call on every health computation.
     */
    public static function corruptFileExists(): bool {
        return (glob(self::path() . '.corrupt-*') ?: []) !== [];
    }

    // ------------------------------------------------------------------
    // Public API (R1)
    // ------------------------------------------------------------------

    /** Every favourite, ordered lastOpenedAt desc, then addedAt desc. */
    public static function list(): array {
        $favourites = self::load()['favourites'];
        usort($favourites, static function (array $a, array $b): int {
            $byOpened = $b['lastOpenedAt'] <=> $a['lastOpenedAt'];
            return $byOpened !== 0 ? $byOpened : ($b['addedAt'] <=> $a['addedAt']);
        });
        return $favourites;
    }

    /** One favourite by id, or null when it does not exist. */
    public static function get(string $id): ?array {
        foreach (self::load()['favourites'] as $f) {
            if ($f['id'] === $id) return $f;
        }
        return null;
    }

    /**
     * Reads the live workspace registry record for $workspaceId (the same
     * sessions list AdminService::getWorkspace() reads via
     * ConfigService::getWorkspaces()) and upserts a favourite from it.
     * Refuses an unknown workspace id.
     *
     * @return array{favourite:array,updated:bool}|array{error:string}
     */
    public static function addFromWorkspace(string $workspaceId): array {
        $sessions = ConfigService::getWorkspaces()['sessions'] ?? [];
        $record = null;
        foreach ($sessions as $w) {
            if (is_array($w) && (string)($w['id'] ?? '') === $workspaceId) {
                $record = $w;
                break;
            }
        }
        if ($record === null) {
            return ['error' => "No workspace with id '$workspaceId' was found."];
        }
        return self::add([
            'name'    => (string)($record['name'] ?? ''),
            'agentId' => (string)($record['agentId'] ?? ''),
            'path'    => (string)($record['path'] ?? ''),
            // VOICE_SWITCHES.md R4: a workspace record without `voice` (older
            // registry, or never toggled) counts as true.
            'voice'   => array_key_exists('voice', $record) ? (bool)$record['voice'] : true,
        ]);
    }

    /**
     * Upserts on the same (agentId, path): a matching existing favourite has
     * its name/voice refreshed and keeps its id; otherwise a new favourite is
     * minted.
     *
     * @return array{favourite:array,updated:bool}|array{error:string}
     */
    public static function add(array $fields): array {
        $agentId = trim((string)($fields['agentId'] ?? ''));
        $path    = trim((string)($fields['path'] ?? ''));
        if ($agentId === '' || $path === '') {
            return ['error' => 'Both agentId and path are required.'];
        }
        $name  = (string)($fields['name'] ?? '');
        $voice = array_key_exists('voice', $fields) ? (bool)$fields['voice'] : true;

        return self::withLock(function () use ($agentId, $path, $name, $voice) {
            $favourites = self::load()['favourites'];
            $now = self::nowMs();
            $updated = false;
            $favourite = null;
            foreach ($favourites as &$f) {
                if ($f['agentId'] === $agentId && $f['path'] === $path) {
                    $f['name']  = $name;
                    $f['voice'] = $voice;
                    $updated = true;
                    $favourite = $f;
                    break;
                }
            }
            unset($f);
            if (!$updated && count($favourites) >= self::MAX_FAVOURITES) {
                return ['error' => 'You already have ' . self::MAX_FAVOURITES . ' favourites — remove one before adding another.'];
            }
            if (!$updated) {
                $favourite = [
                    'id'           => self::mintId($favourites),
                    'name'         => $name,
                    'agentId'      => $agentId,
                    'path'         => $path,
                    'voice'        => $voice,
                    'addedAt'      => $now,
                    'lastOpenedAt' => 0,
                ];
                $favourites[] = $favourite;
            }
            if (!self::save($favourites)) {
                return ['error' => 'Could not write favourites.json.'];
            }
            return ['favourite' => $favourite, 'updated' => $updated];
        });
    }

    /**
     * Only `name` and `voice` may change.
     *
     * @return array{favourite:array}|array{error:string}
     */
    public static function update(string $id, array $patch): array {
        return self::withLock(function () use ($id, $patch) {
            $favourites = self::load()['favourites'];
            $found = null;
            foreach ($favourites as &$f) {
                if ($f['id'] === $id) {
                    if (array_key_exists('name', $patch))  $f['name']  = (string)$patch['name'];
                    if (array_key_exists('voice', $patch)) $f['voice'] = (bool)$patch['voice'];
                    $found = $f;
                    break;
                }
            }
            unset($f);
            if ($found === null) {
                return ['error' => "No favourite with id '$id' was found."];
            }
            if (!self::save($favourites)) {
                return ['error' => 'Could not write favourites.json.'];
            }
            return ['favourite' => $found];
        });
    }

    /** Removes one favourite. Returns true only when a matching row was found and removed. */
    public static function remove(string $id): bool {
        return self::withLock(function () use ($id) {
            $favourites = self::load()['favourites'];
            $filtered = array_values(array_filter($favourites, static fn(array $f): bool => $f['id'] !== $id));
            if (count($filtered) === count($favourites)) {
                return false;
            }
            return self::save($filtered);
        });
    }

    /** Sets lastOpenedAt to now. Returns true only when the favourite exists. */
    public static function touch(string $id): bool {
        return self::withLock(function () use ($id) {
            $favourites = self::load()['favourites'];
            $found = false;
            foreach ($favourites as &$f) {
                if ($f['id'] === $id) {
                    $f['lastOpenedAt'] = self::nowMs();
                    $found = true;
                    break;
                }
            }
            unset($f);
            if (!$found) {
                return false;
            }
            return self::save($favourites);
        });
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /**
     * Reads the favourites file. A missing file reads back as empty. A file
     * that is not valid JSON, or has no `favourites` array, is renamed aside
     * to `<path>.corrupt-<epoch>` and treated as empty — never fatal, and the
     * corrupt copy is kept for the operator to inspect.
     *
     * @return array{version:int,favourites:list<array>}
     */
    private static function load(): array {
        $empty = ['version' => self::SCHEMA_VERSION, 'favourites' => []];
        $file = self::path();
        if (!is_file($file)) {
            return $empty;
        }
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['favourites'] ?? null)) {
            $aside = $file . '.corrupt-' . time();
            if (@rename($file, $aside)) {
                LogService::log(
                    'favourites.json was not valid JSON — set aside as ' . basename($aside),
                    LogService::LOG_WARN,
                    'FavouritesService'
                );
            }
            return $empty;
        }
        $favourites = [];
        foreach ($data['favourites'] as $f) {
            if (!is_array($f) || (string)($f['id'] ?? '') === '') continue;
            $favourites[] = self::normalize($f);
        }
        return ['version' => self::SCHEMA_VERSION, 'favourites' => $favourites];
    }

    /** Fills every field with its documented default so a caller never has to guess a missing key. */
    private static function normalize(array $f): array {
        return [
            'id'           => (string)($f['id'] ?? ''),
            'name'         => (string)($f['name'] ?? ''),
            'agentId'      => (string)($f['agentId'] ?? ''),
            'path'         => (string)($f['path'] ?? ''),
            'voice'        => array_key_exists('voice', $f) ? (bool)$f['voice'] : true,
            'addedAt'      => (int)($f['addedAt'] ?? 0),
            'lastOpenedAt' => (int)($f['lastOpenedAt'] ?? 0),
        ];
    }

    /** Atomic temp+rename write of the whole favourites list. */
    private static function save(array $favourites): bool {
        $dir = dirname(self::path());
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return AtomicWriteService::writeJson(self::path(), [
            'version'     => self::SCHEMA_VERSION,
            'favourites'  => array_values($favourites),
        ]);
    }

    /**
     * Runs $fn while holding a short-lived, non-blocking lock on a TMPFS file
     * (never Flash) so two concurrent read-modify-write calls (e.g. two
     * PHP-FPM workers racing an upsert) cannot clobber each other. Best
     * effort: if the lock file cannot even be opened, $fn still runs
     * unlocked rather than failing the whole request.
     */
    private static function withLock(callable $fn) {
        $lockPath = self::lockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return $fn();
        }
        $locked = false;
        for ($tries = 0; $tries < self::LOCK_RETRIES; $tries++) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(self::LOCK_RETRY_DELAY_US);
        }
        try {
            return $fn();
        } finally {
            if ($locked) @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function nowMs(): int {
        return (int)round(microtime(true) * 1000);
    }

    /** 'f' + 5 random [a-z0-9] characters, retried against $existing until it is unique. */
    private static function mintId(array $existing): string {
        $taken = array_fill_keys(array_column($existing, 'id'), true);
        $alphabetMax = strlen(self::ID_CHARS) - 1;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < self::ID_LEN; $i++) {
                $suffix .= self::ID_CHARS[random_int(0, $alphabetMax)];
            }
            $id = 'f' . $suffix;
            if (!isset($taken[$id])) return $id;
        }
        // Vanishingly unlikely fallback: 20 collisions in a row against a 36^5 space.
        return 'f' . substr(bin2hex(random_bytes(4)), 0, self::ID_LEN);
    }
}
