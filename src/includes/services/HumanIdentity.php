<?php
/**
 * <module_context>
 *     <name>HumanIdentity</name>
 *     <description>Names the person behind a web request, for the records of who acted
 *     (docs/specs/HUMAN_ACTOR_IDENTITY.md). The name is the Unraid login plus the optional
 *     display name from Settings, for example "root (John)".</description>
 *     <dependencies>ConfigService (display name only, optional and guarded).</dependencies>
 *     <constraints>Never throws. Holds no session lock: it reads the session with read_and_close.
 *     An agent call (AICLI_SESSION_ID set) and a process outside the web server give null: an agent
 *     is recorded as its workspace, and the system has no person. The display name is for attribution
 *     only and never grants a right.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class HumanIdentity {

    /** Test seam. null = resolve from the request. false = no person. array = this identity. */
    public static $override = null;

    /** Longest display name, in characters. */
    public const DISPLAY_MAX = 40;

    /** @var array<string,mixed>|false|null  Cache for one request. */
    private static $cache = null;

    /**
     * The person behind this request, or null when no person is known.
     *
     * @return array{kind:string,login:string,display:string,name:string}|null
     */
    public static function current(): ?array {
        if (self::$override === false) return null;
        if (is_array(self::$override)) return self::$override;
        if ((string)(getenv('AICLI_SESSION_ID') ?: '') !== '') return null;
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || empty($_SERVER['REQUEST_METHOD'])) return null;
        if (self::$cache === null) {
            try {
                self::$cache = self::build(self::sessionLogin(), self::configuredDisplay());
            } catch (\Throwable $e) {
                self::$cache = false;
            }
        }
        return self::$cache === false ? null : self::$cache;
    }

    /** The text for a record: "root (John)", "root", or '' when no person is known. */
    public static function name(): string {
        $who = self::current();
        return $who === null ? '' : $who['name'];
    }

    /** Forget the cached answer (a test, or a long-running worker). */
    public static function reset(): void {
        self::$cache = null;
    }

    /**
     * Build the identity from a login and a display name. A bad login becomes "root".
     *
     * @return array{kind:string,login:string,display:string,name:string}
     */
    public static function build(string $login, string $display): array {
        $login = (string)preg_replace('/[^A-Za-z0-9._-]/', '', $login);
        $login = substr($login, 0, 32);
        if ($login === '') $login = 'root';
        $display = self::sanitizeDisplay($display);
        return [
            'kind'    => 'human',
            'login'   => $login,
            'display' => $display,
            'name'    => $display !== '' ? "$login ($display)" : $login,
        ];
    }

    /** A display name: no control characters, no parentheses, one line, at most DISPLAY_MAX characters. */
    public static function sanitizeDisplay(string $raw): string {
        $clean = (string)preg_replace('/[\x00-\x1F\x7F()]/u', ' ', $raw);
        if ($clean === '' && $raw !== '') $clean = (string)preg_replace('/[\x00-\x1F\x7F()]/', ' ', $raw);
        $clean = trim((string)preg_replace('/\s+/u', ' ', $clean));
        return function_exists('mb_substr') ? trim(mb_substr($clean, 0, self::DISPLAY_MAX)) : trim(substr($clean, 0, self::DISPLAY_MAX));
    }

    /**
     * The login name that the Unraid web login stored in the session. Only root can sign in to the Unraid
     * web page, so a request without a name in the session is still root: Unraid has checked the login
     * before the request reached the plugin.
     */
    private static function sessionLogin(): string {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return (string)($_SESSION['unraid_user'] ?? '');
        }
        if (headers_sent() || empty($_COOKIE)) return '';
        // read_and_close reads the session and releases the lock at once.
        if (@session_start(['read_and_close' => true])) {
            return (string)($_SESSION['unraid_user'] ?? '');
        }
        return '';
    }

    private static function configuredDisplay(): string {
        if (!class_exists(ConfigService::class)) return '';
        $config = ConfigService::getConfig();
        return is_array($config) ? (string)($config['human_display_name'] ?? '') : '';
    }
}
