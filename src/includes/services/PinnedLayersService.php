<?php
/**
 * Flash space that deleted home and agent layers still pin, and who pins it.
 *
 * Spec: docs/specs/STORAGE_PINNED_LAYERS.md (Forgejo #455).
 *
 * After a merge the old layer files are deleted. A process that holds a file on the old home
 * mount keeps that mount, its layers and their loop devices alive, so the space on the boot
 * drive is not freed. This service only READS: it reports the pinned size and the workspaces
 * that hold an old mount. It never detaches a loop device or stops a process.
 *
 * Seams (tests): AICLI_SL_LOSETUP (a fixture of `losetup -a`), AICLI_SL_LOOP_SYSFS_ROOT.
 */

namespace AICliAgents\Services;

final class PinnedLayersService
{
    /** @return array{loops:int,mb:int,layers:string[]} */
    public static function pinned(): array
    {
        $root = rtrim((string)(getenv('AICLI_SL_LOOP_SYSFS_ROOT') ?: '/sys/block'), '/');
        $fixture = getenv('AICLI_SL_LOSETUP');
        $raw = ($fixture !== false && $fixture !== '' && is_readable($fixture))
            ? (string)@file_get_contents($fixture)
            : (string)@shell_exec('losetup -a 2>/dev/null');
        $loops = 0;
        $bytes = 0;
        $layers = [];
        foreach (explode("\n", $raw) as $line) {
            if (strpos($line, 'unraid-aicliagents') === false) continue;
            if (!preg_match('#\(([^()]*\.sqsh)\s*\(deleted\)\)#', $line, $m)) continue;
            $dev = basename(trim((string)(explode(':', $line, 2)[0] ?? '')));
            if (!preg_match('/^loop[0-9]+$/D', $dev)) continue;
            $sectors = (int)trim((string)@file_get_contents($root . '/' . $dev . '/size'));
            $loops++;
            $bytes += $sectors * 512;
            $layers[] = basename($m[1]);
        }
        return ['loops' => $loops, 'mb' => (int)round($bytes / 1048576), 'layers' => $layers];
    }

    /**
     * Pure: from process facts, the session ids that hold a file that is no longer the file the
     * same path names today. An overlay reports the device of the layer a file lives in, so the
     * device of an open file says nothing alone; the (device, inode) pair of the open file is
     * compared with the pair of the same path now. A path that no longer exists counts too.
     *
     * @param array<int,array{session:string,files:array<int,array{path:string,dev:int,ino:int,now_dev:?int,now_ino:?int}>}> $procs
     * @return array<string,int> session id => number of files that are not the file the path names now
     */
    public static function holdersFrom(array $procs): array
    {
        $out = [];
        foreach ($procs as $p) {
            $sid = (string)($p['session'] ?? '');
            if ($sid === '') continue;
            foreach (($p['files'] ?? []) as $f) {
                $nowDev = $f['now_dev'] ?? null;
                $nowIno = $f['now_ino'] ?? null;
                if ($nowDev === null || $nowIno === null || (int)$f['dev'] !== (int)$nowDev || (int)$f['ino'] !== (int)$nowIno) {
                    $out[$sid] = ($out[$sid] ?? 0) + 1;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Seams (tests): $procRoot is the folder that holds the process folders (default /proc).
     * $statOpen returns the stat array of an open-file link (default stat()). A fixture tree
     * cannot fake the kernel link to an open file, so a test passes its own stat function.
     *
     * @param ?callable(string):(array<int|string,int>|false) $statOpen
     * @return array<string,int> session id => open files that are not what their path names now
     */
    public static function staleHomeHolders(?string $procRoot = null, ?callable $statOpen = null): array
    {
        $procRoot = rtrim($procRoot ?? '/proc', '/');
        $statOpen = $statOpen ?? static fn(string $link) => @stat($link);
        $procs = [];
        foreach (@glob($procRoot . '/[0-9]*') ?: [] as $p) {
            $env = @file_get_contents($p . '/environ');
            if ($env === false || !preg_match('/(?:^|\0)AICLI_SESSION_ID=([A-Za-z0-9_-]{1,128})(?:\0|$)/', $env, $m)) continue;
            $files = [];
            $links = array_merge([$p . '/cwd'], @glob($p . '/fd/*') ?: []);
            foreach ($links as $l) {
                $t = @readlink($l);
                $workPrefix = rtrim(SecretPaths::stateRoot(), '/') . '/work/';
                if ($t === false || strpos($t, $workPrefix) !== 0) continue;
                if (substr($t, -10) === ' (deleted)') continue;   // a file deleted on purpose (a temp file) is not a stale mount
                $st = $statOpen($l);
                if ($st === false) continue;
                $now = @stat($t);
                $files[] = ['path' => $t, 'dev' => (int)$st['dev'], 'ino' => (int)$st['ino'],
                    'now_dev' => $now === false ? null : (int)$now['dev'], 'now_ino' => $now === false ? null : (int)$now['ino']];
            }
            if ($files !== []) $procs[] = ['session' => $m[1], 'files' => $files];
        }
        return self::holdersFrom($procs);
    }
}
