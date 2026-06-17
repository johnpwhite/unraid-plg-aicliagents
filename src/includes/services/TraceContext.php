<?php
/**
 * <module_context>
 *     <name>TraceContext</name>
 *     <description>Per-request trace-correlation id (R-06). Holds the 8-hex join key that links AJAX → PHP → shell → queue → supervisor log lines.</description>
 *     <dependencies>None</dependencies>
 *     <constraints>Dependency-free, static only, under 60 lines. Safe to require from LogService / LifecycleLogService (no circular deps). Id format is strictly [a-z0-9]{4,16} so it can be interpolated into shell env prefixes without escaping.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class TraceContext {
    /** Strict id shape — lowercase alnum, 4–16 chars. Shell-safe by construction. */
    public const ID_PATTERN = '/^[a-z0-9]{4,16}$/';

    private static ?string $id = null;

    /**
     * Adopt a trace id for this request. Invalid-format ids are rejected
     * (returns false, id unchanged) so a hostile header can never inject
     * shell metacharacters or log noise.
     */
    public static function setId(string $id): bool {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return false;
        }
        self::$id = $id;
        return true;
    }

    /** Current trace id, or null when none was set this request. */
    public static function getId(): ?string {
        return self::$id;
    }

    /** Test hook: clear the per-request id (PHP-FPM gives a fresh process state in prod). */
    public static function reset(): void {
        self::$id = null;
    }

    /** Generate a fresh 8-hex id (random_bytes; low collision within a session). */
    public static function generate(): string {
        return bin2hex(random_bytes(4));
    }

    /**
     * Shell env prefix for exec/proc_open call sites that launch storage or
     * supervisor scripts: "AICLI_TRACE_ID=<id> " when an id is set, '' when not.
     * The id is validated at setId() time, so direct interpolation is safe.
     */
    public static function shellPrefix(): string {
        return self::$id !== null ? 'AICLI_TRACE_ID=' . self::$id . ' ' : '';
    }
}
