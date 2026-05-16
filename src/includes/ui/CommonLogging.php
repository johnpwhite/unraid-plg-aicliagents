<?php
/**
 * <module_context>
 * Description: Shared JavaScript logging helper for AICliAgents.
 * Dependencies: AICliAjax.php?action=log.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<script>
/**
 * Sends a log message from the client to the server debug.log.
 * @param {string} msg The message to log.
 * @param {number} level The log level (0=ERROR, 1=WARN, 2=INFO, 3=DEBUG).
 * @param {string} context The component context (e.g., [ManagerStorage]).
 */
function aicli_log_to_server(msg, level = 2, context = "Frontend") {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    $.post('/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + token, {
        message: msg,
        level: level,
        context: context,
        csrf_token: token
    }).fail(function() {
        console.error("[AICliAgents] Failed to send log to server:", msg);
    });
}

// Intercept window errors. SOURCE FILTER (2026-05-13): only report errors that
// originate from our own JS — drop anything sourced from Unraid's WebGUI bundle
// (e.g. /webGui/javascript/dynamix.js) or from cross-origin scripts. Background:
// Unraid 7.3.0 introduced a `ReferenceError: Can't find variable: codeAZ` in its
// minified dynamix.js that fires dozens of times per page load on every plugin's
// page (it's an upstream Unraid regression, not ours). Without this filter our
// debug.log fills with hundreds of identical noise lines per session, drowning
// the signal from real plugin issues. We still console.error everything so a
// developer with DevTools open sees the full picture; we just don't ship the
// Unraid-internal noise upstream to our log file.
window.onerror = function(msg, url, lineNo, columnNo, error) {
    const detail = msg + " at " + url + ":" + lineNo + ":" + columnNo;
    console.error("[AICliAgents] Frontend Error:", detail);
    const u = String(url || '');
    // Match the WebGUI base regardless of host (myunraid.net subdomain hash, LAN IP, …).
    const isUnraidInternal = /\/webGui\//.test(u);
    // Cross-origin / opaque errors (browser strips url to "") aren't actionable from a plugin.
    const isOpaque = u === '' || u === 'null' || u === 'undefined';
    if (isUnraidInternal || isOpaque) {
        return false;
    }
    aicli_log_to_server("JS ERROR: " + detail, 0); // 0 = ERROR
    return false;
};

// Log high-impact user actions
console.log("[AICliAgents] Client-side logging initialized.");
</script>
