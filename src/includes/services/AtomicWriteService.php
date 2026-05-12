<?php
/**
 * <module_context>
 *     <name>AtomicWriteService</name>
 *     <description>Crash-safe, race-safe file writes via temp+rename.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Pure utility. Routes every offender that previously called naked file_put_contents through this helper.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AtomicWriteService {
    /**
     * Writes $content to $path atomically (temp+rename). Concurrent writers
     * produce distinct tmp paths via getmypid() + microtime, so two PHP-FPM
     * workers racing on the same target file cannot corrupt each other's bytes.
     *
     * Returns true on success, false on any I/O failure (caller should log).
     */
    public static function write(string $path, string $content): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpPath = $path . '.tmp.' . getmypid() . '.' . str_replace('.', '', (string)microtime(true));

        $written = @file_put_contents($tmpPath, $content);
        if ($written === false) {
            @unlink($tmpPath);
            return false;
        }

        $fd = @fopen($tmpPath, 'r+');
        if ($fd !== false) {
            if (function_exists('fsync')) {
                @fsync($fd);
            }
            @fclose($fd);
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        // Flash durability: writes under /boot/ (the FAT32 USB) otherwise sit in
        // the OS page cache and have been lost across reboots that didn't cleanly
        // flush — e.g. secrets.cfg / the plugin .cfg / the .plg reverting to an
        // older state on the test box. fsync above flushed the temp file's data;
        // the rename + directory metadata still need flushing, and FAT32 has no
        // journal to recover from. Flush the whole filesystem the file lives on
        // (cheap — /boot is tiny). RAM-overlay writes (~/.aicli/... under /tmp)
        // are deliberately excluded — syncing RAM is pointless and they're frequent.
        if (strncmp($path, '/boot/', 6) === 0) {
            self::syncFilesystem($path);
        }

        return true;
    }

    /**
     * Flush the OS page cache for the filesystem containing $path (falls back to
     * a global sync). Uses proc_open array form — no shell, no injection surface.
     * Best-effort: any failure is swallowed (the write already succeeded).
     */
    private static function syncFilesystem(string $path): void {
        $attempts = [['sync', '-f', $path], ['sync']];
        foreach ($attempts as $argv) {
            $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) continue;
            foreach ([1, 2] as $i) {
                if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                    @stream_get_contents($pipes[$i]);
                    @fclose($pipes[$i]);
                }
            }
            $rc = @proc_close($proc);
            if ($rc === 0) return; // `sync -f <path>` worked — skip the global fallback
        }
    }

    /**
     * JSON convenience: encode + write atomically. Returns false on encode or
     * I/O failure. Callers needing to distinguish should call write() directly.
     */
    public static function writeJson(string $path, $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): bool {
        $json = json_encode($data, $flags);
        if ($json === false) {
            return false;
        }
        return self::write($path, $json);
    }
}
