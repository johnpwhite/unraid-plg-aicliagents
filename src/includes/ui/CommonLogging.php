<?php
/**
 * <module_context>
 * Description: Shared JavaScript logging + AJAX helpers for AICliAgents.
 * Dependencies: AICliAjax.php?action=log.
 * Constraints: Atomic UI fragment (< 110 lines). aicliAjax() is the canonical
 * jQuery AJAX wrapper (R-06): it stamps every call with an X-Aicli-Trace id so
 * server/shell log lines are grep-joinable per request.
 * </module_context>
 */
?>
<script>
// R-06 trace correlation: 4-hex per-page-load prefix + 4-hex per-call suffix
// = an 8-char [a-z0-9] id the server adopts verbatim (validated server-side).
// The shared prefix groups all calls from one page visit; the suffix separates
// individual requests.
window._aicliTracePrefix = window._aicliTracePrefix ||
    ('0000' + Math.floor(Math.random() * 0xffff).toString(16)).slice(-4);
function aicliTraceId() {
    return window._aicliTracePrefix +
        ('0000' + Math.floor(Math.random() * 0xffff).toString(16)).slice(-4);
}

/**
 * Canonical AJAX helper (R-06): GET to AICliAjax.php with the csrf token and a
 * fresh X-Aicli-Trace header. Returns the jqXHR (use .done/.fail), so existing
 * `$.getJSON(url, cb)` call sites convert as `aicliAjax(action, params, cb)`.
 * @param {string} action  AJAX action name.
 * @param {Object} [params] Extra query params (values URL-encoded here).
 * @param {Function} [done] Optional success callback (parsed JSON).
 */
function aicliAjax(action, params, done) {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    const qs = $.param(Object.assign({ action: action, csrf_token: token }, params || {}));
    const xhr = $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?' + qs,
        dataType: 'json',
        headers: { 'X-Aicli-Trace': aicliTraceId() }
    });
    if (done) xhr.done(done);
    return xhr;
}

/**
 * Sends a log message from the client to the server debug.log.
 * @param {string} msg The message to log.
 * @param {number} level The log level (0=ERROR, 1=WARN, 2=INFO, 3=DEBUG).
 * @param {string} context The component context (e.g., [ManagerStorage]).
 */
function aicli_log_to_server(msg, level = 2, context = "Frontend") {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + token,
        method: 'POST',
        headers: { 'X-Aicli-Trace': aicliTraceId() },
        data: {
            message: msg,
            level: level,
            context: context,
            csrf_token: token
        }
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
