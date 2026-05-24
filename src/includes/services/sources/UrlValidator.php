<?php
/**
 * <module_context>
 *     <name>UrlValidator</name>
 *     <description>Tiny static utility: validates that vendor / manifest / install URLs are HTTPS before they reach curl/wget. WP #1083 defence-in-depth — registered vendors all use HTTPS today, but a custom-registered agent (or a future feature) could supply HTTP, which would silently downgrade transport security. Fail closed at the validation boundary.</description>
 *     <dependencies>LogService (for error logging).</dependencies>
 *     <constraints>Pure helper. Always returns bool; logs on rejection. Callers must check the return value and bail.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Sources;

use AICliAgents\Services\LogService;

class UrlValidator {
    /**
     * Validates that $url uses the HTTPS scheme. Returns true if so. Returns
     * false (and logs an error tagged with $context) if the URL is empty, has
     * no scheme, or uses any non-HTTPS scheme (http, ftp, file, javascript,
     * data, etc.).
     *
     * Permissive on case (HTTPS / Https / https all accepted). Strict on the
     * rest — only the exact `https` scheme passes. We do NOT allow `ftps`,
     * `sftp`, or any other "secure-ish" scheme here; this util is specifically
     * about web fetches that wind up in curl/wget.
     *
     * @param string $url     The URL to validate.
     * @param string $context Caller identifier for the log line (e.g. "CurlInstallSource::fetch script_url").
     */
    public static function requireHttps(string $url, string $context): bool {
        if ($url === '') {
            LogService::log("UrlValidator: empty URL rejected for $context", LogService::LOG_ERROR, 'UrlValidator');
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || strtolower($scheme) !== 'https') {
            $reported = is_string($scheme) ? $scheme : '(none)';
            LogService::log("UrlValidator: non-HTTPS URL rejected for $context (scheme=$reported, url=$url)", LogService::LOG_ERROR, 'UrlValidator');
            return false;
        }
        return true;
    }
}
