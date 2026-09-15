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
    /** @var array{stage:string,path:string,warning:string}|null Diagnostic for the most recent write in this request. */
    private static ?array $lastFailure = null;

    /**
     * The I/O boundary normally returns only false, which made an OverlayFS
     * ESTALE indistinguishable from disk-full or a permission failure. Keep a
     * request-local diagnostic for callers and logs without exposing PHP
     * warnings to an AJAX response.
     *
     * @return array{stage:string,path:string,warning:string}|null
     */
    public static function lastFailure(): ?array {
        return self::$lastFailure;
    }

    /** True when the last failed operation was an OverlayFS stale-handle error. */
    public static function lastFailureIsStaleHandle(): bool {
        return self::$lastFailure !== null
            && stripos(self::$lastFailure['warning'], 'stale file handle') !== false;
    }

    /** Execute one I/O operation while retaining (rather than emitting) its warning. */
    private static function captureWarning(callable $operation): array {
        $warning = '';
        set_error_handler(static function (int $_severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        return [$result, $warning];
    }

    private static function fail(string $stage, string $path, string $warning = ''): bool {
        self::$lastFailure = ['stage' => $stage, 'path' => $path, 'warning' => $warning];
        return false;
    }

    /**
     * Writes $content to $path atomically (temp+rename). Concurrent writers
     * produce distinct tmp paths via getmypid() + microtime, so two PHP-FPM
     * workers racing on the same target file cannot corrupt each other's bytes.
     *
     * Returns true on success, false on any I/O failure (caller should log).
     */
    public static function write(string $path, string $content): bool {
        self::$lastFailure = null;
        // #151 (mvp-dmoe): rename() REPLACES a symlink target rather than writing
        // through it, so a managed file the user linked into their config repo (e.g.
        // ~/.claude/CLAUDE.md -> dotfiles repo) silently becomes a plain file the first
        // time a projector writes it, and the repo quietly stops receiving edits.
        // Resolve a live link to its real target so the temp file lands beside the
        // target and renames ONTO it, keeping the link. A dangling link (realpath
        // false) falls through and is replaced, matching the previous behaviour.
        if (is_link($path)) {
            $real = realpath($path);
            if ($real !== false) $path = $real;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            [$made, $warning] = self::captureWarning(static fn() => mkdir($dir, 0755, true));
            if (!$made && !is_dir($dir)) return self::fail('mkdir', $path, $warning);
        }

        $tmpPath = $path . '.tmp.' . getmypid() . '.' . str_replace('.', '', (string)microtime(true));

        // Bug #770 channel-persist flake: file_put_contents is fine in theory
        // (it doesn't return until all bytes are in the kernel), but on a
        // load-stressed Flash FS we still saw cross-process visibility lag
        // after a successful save. The explicit fopen + stream_set_write_buffer
        // path makes the buffer policy unambiguous (no userland buffer at all,
        // bytes go straight to the kernel on every fwrite), and pairing it
        // with an explicit fflush+fsync before close gives durability before
        // the rename.
        [$fh, $warning] = self::captureWarning(static fn() => fopen($tmpPath, 'w'));
        if ($fh === false) {
            return self::fail('fopen', $path, $warning);
        }
        stream_set_write_buffer($fh, 0);
        [$written, $warning] = self::captureWarning(static fn() => fwrite($fh, $content));
        if ($written === false || $written !== strlen($content)) {
            fclose($fh);
            @unlink($tmpPath);
            return self::fail('fwrite', $path, $warning);
        }
        [$flushed, $warning] = self::captureWarning(static fn() => fflush($fh));
        if (!$flushed) {
            fclose($fh);
            @unlink($tmpPath);
            return self::fail('fflush', $path, $warning);
        }
        if (function_exists('fsync')) {
            @fsync($fh); // Best effort: some supported filesystems do not implement it.
        }
        fclose($fh);

        [$renamed, $warning] = self::captureWarning(static fn() => rename($tmpPath, $path));
        if (!$renamed) {
            @unlink($tmpPath);
            return self::fail('rename', $path, $warning);
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
