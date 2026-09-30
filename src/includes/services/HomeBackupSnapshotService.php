<?php
/**
 * <module_context>
 *     <name>HomeBackupSnapshotService</name>
 *     <description>HOME_BACKUP.md "2026-09-24 follow-up": delete ONE home-backup
 *       snapshot from a home's backup folder
 *       (<target>/aicli-home-backup/<user>/<YYYYMMDDTHHMMSSZ>/). The `latest`
 *       link is moved to the newest snapshot that is left (or removed when none
 *       is left), so the next backup still hard-links against a real snapshot.</description>
 *     <dependencies>none (pure filesystem; the caller supplies the target and the busy state)</dependencies>
 *     <constraints>Removes only a directory whose name is a snapshot timestamp,
 *       that sits directly inside the home's own backup folder, and that is not
 *       a symbolic link. Never follows a symbolic link while it deletes. Refuses
 *       while a backup or restore of that home is queued or running.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class HomeBackupSnapshotService {
    /** A snapshot directory name: the UTC time the backup started. */
    public const NAME_RE = '/^\d{8}T\d{6}Z$/';

    /**
     * Delete one snapshot.
     *
     * @param string $user        the home (validated here too)
     * @param string $target      the folder that holds `aicli-home-backup/<user>` —
     *                            the home's own target or, when it has none, the
     *                            target its last run used (the same folder the
     *                            Snapshots list reads)
     * @param string $snapshotArg a snapshot path from `list_backups`, or its bare name
     * @param bool   $busy        true while a backup or restore of this home is queued/running
     * @return array{status:string,message?:string,deleted?:string,name?:string,latest?:string}
     */
    public static function delete(string $user, string $target, string $snapshotArg, bool $busy): array {
        if ($user === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $user) || $user === '.' || $user === '..') {
            return self::err('invalid_user');
        }
        if ($busy) {
            return self::err("A backup or restore of $user's home is running. Delete the snapshot when it has finished.");
        }
        $target = rtrim(trim($target), '/');
        if ($target === '' || $target[0] !== '/') {
            return self::err("No backup folder is known for $user's home, so there is no snapshot to delete.");
        }
        $snapshotArg = trim($snapshotArg);
        if ($snapshotArg === '' || strlen($snapshotArg) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $snapshotArg)) {
            return self::err('Choose a snapshot to delete.');
        }

        $root = $target . '/aicli-home-backup/' . $user;
        if (is_link($root)) {
            return self::err("The backup folder of $user's home is a symbolic link. Nothing was deleted.");
        }
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            return self::err("There are no snapshots of $user's home in $target.");
        }
        $realRoot = rtrim($realRoot, '/');

        // The argument is either a bare snapshot name or a path whose parent
        // is EXACTLY this home's backup folder (literal or canonical form).
        $name = basename($snapshotArg);
        if (strpos($snapshotArg, '/') !== false) {
            $parent = rtrim(dirname($snapshotArg), '/');
            if ($parent !== $root && $parent !== $realRoot) {
                return self::err("That snapshot is not inside $user's own backup folder. Nothing was deleted.");
            }
        }
        if (!preg_match(self::NAME_RE, $name)) {
            return self::err('That is not a snapshot folder. Nothing was deleted.');
        }

        $dir = $realRoot . '/' . $name;
        if (is_link($dir)) {
            return self::err('That snapshot is a symbolic link. Nothing was deleted.');
        }
        if (!is_dir($dir)) {
            return self::err('That snapshot no longer exists.');
        }
        $realDir = realpath($dir);
        if ($realDir === false || $realDir !== $dir) {
            return self::err("That snapshot is not inside $user's own backup folder. Nothing was deleted.");
        }

        // 1. Move it aside in one rename: it leaves the list at once, and a
        //    half-deleted tree is never shown as a snapshot (a dot name does
        //    not match NAME_RE, so list_backups and the retention prune skip it).
        $trash = $realRoot . '/.deleting-' . $name . '-' . bin2hex(random_bytes(4));
        if (!@rename($dir, $trash)) {
            return self::err('The snapshot could not be moved for deletion. Check the backup folder permissions. Nothing was deleted.');
        }
        // 2. Point `latest` at the newest snapshot that is left.
        $latest = self::repointLatest($realRoot, $name);
        // 3. Remove the moved tree without following any symbolic link in it.
        $complete = self::removeTree($trash);
        $excludes = $realRoot . '/' . $name . '.excludes';
        if (is_file($excludes) && !is_link($excludes)) @unlink($excludes);

        $out = ['status' => 'ok', 'deleted' => $dir, 'name' => $name, 'latest' => $latest];
        if (!$complete) {
            $out['warning'] = 'The snapshot is gone from the list, but some of its files could not be removed from ' . $trash . '.';
        }
        return $out;
    }

    /**
     * When `latest` names the deleted snapshot (or points nowhere), point it
     * at the newest snapshot left, or remove it when none is left.
     * Returns the name `latest` now points to ('' when none).
     */
    private static function repointLatest(string $realRoot, string $deletedName): string {
        $link = $realRoot . '/latest';
        $current = is_link($link) ? basename((string)@readlink($link)) : '';
        $names = [];
        foreach (@scandir($realRoot) ?: [] as $entry) {
            if (!preg_match(self::NAME_RE, $entry)) continue;
            $p = $realRoot . '/' . $entry;
            if (is_link($p) || !is_dir($p)) continue;
            $names[] = $entry;
        }
        rsort($names, SORT_STRING);
        if (is_link($link) && $current !== $deletedName && in_array($current, $names, true)) {
            return $current; // still valid
        }
        if (!is_link($link) && file_exists($link)) {
            return ''; // not ours to change (the job only ever makes a link)
        }
        if ($names === []) {
            if (is_link($link)) @unlink($link);
            return '';
        }
        $tmp = $realRoot . '/.latest-' . bin2hex(random_bytes(4));
        if (@symlink($names[0], $tmp) && @rename($tmp, $link)) {
            return $names[0];
        }
        @unlink($tmp);
        return $current;
    }

    /** Depth-first delete that unlinks a symbolic link and never enters it. */
    private static function removeTree(string $path): bool {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) return !file_exists($path);
        $ok = true;
        $h = @opendir($path);
        if ($h === false) return false;
        while (($entry = readdir($h)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            if (is_link($child) || !is_dir($child)) {
                if (!@unlink($child)) $ok = false;
            } elseif (!self::removeTree($child)) {
                $ok = false;
            }
        }
        closedir($h);
        return @rmdir($path) && $ok;
    }

    private static function err(string $message): array {
        return ['status' => 'error', 'message' => $message];
    }
}
