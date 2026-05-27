<?php
/**
 * <module_context>
 * Description: Global JavaScript state variables for AICliAgents Terminal.
 * Dependencies: $csrf_token, $version.
 * Constraints: Atomic UI fragment (< 50 lines).
 * </module_context>
 */
?>
<script>
window.csrf_token = <?= json_encode($csrf_token) ?> || (typeof csrf_token !== 'undefined' ? csrf_token : '');
window.aicli_version = <?= json_encode($version) ?>;
window._aicli_target_path = localStorage.getItem('aicli_last_path') || '/mnt/user';
document.documentElement.classList.add('aicli-terminal-page');
// D-400: Permanently suppress Unraid's "unsaved changes" dialog on the terminal page.
// The React UI manages its own persistence — Unraid's form tracker is not applicable.
// Intercepts addEventListener and jQuery.on to block ALL beforeunload registration.
(function() {
    // D-404 hardening: neuter the CANCELLATION mechanism so any beforeunload
    // handler — ours, Unraid's, or third-party — cannot trigger the dialog.
    //
    // Three layers, in order:
    //   1. Lock Event.prototype.preventDefault to no-op when type==='beforeunload'.
    //      defineProperty with writable:false beats plain re-assignment by libs
    //      that load after us and try to restore the native preventDefault.
    //   2. Lock BeforeUnloadEvent.prototype.returnValue setter to swallow writes.
    //      Covers both the legacy `e.returnValue = ''` pattern AND the case
    //      where a handler returns a string (browser writes it to returnValue
    //      via the internal slot, which a prototype-level setter intercepts).
    //   3. Capture-phase stopImmediatePropagation on window as defence-in-depth
    //      for any handler registered via channels we can't intercept.
    //
    // Type gate ('beforeunload') keeps other events intact — React SyntheticEvent
    // uses preventDefault + returnValue for non-beforeunload events.
    try {
        var _origPD_term = Event.prototype.preventDefault;
        Object.defineProperty(Event.prototype, 'preventDefault', {
            value: function() {
                if (this && this.type === 'beforeunload') return;
                return _origPD_term.apply(this, arguments);
            },
            writable: false, configurable: false, enumerable: false
        });
    } catch(e) {}
    try {
        if (window.BeforeUnloadEvent) {
            Object.defineProperty(BeforeUnloadEvent.prototype, 'returnValue', {
                get: function() { return ''; },
                set: function(_v) { /* swallow — never prompt */ },
                configurable: false
            });
        }
    } catch(e) {}
    try {
        EventTarget.prototype.addEventListener.call(window, 'beforeunload', function(e) {
            e.stopImmediatePropagation();
        }, true);
    } catch(e) {}
    try { window.formHasUnsavedChanges = false; } catch(e) {}
    try {
        Object.defineProperty(window, 'formHasUnsavedChanges', { get: function() { return false; }, set: function() {}, configurable: false });
    } catch(e) {}
    window.onbeforeunload = null;
    var origAddEventListener = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function(type, fn, opts) {
        if (type === 'beforeunload') return;
        return origAddEventListener.call(this, type, fn, opts);
    };
    if (typeof jQuery !== 'undefined') {
        jQuery(function() {
            window.onbeforeunload = null;
            jQuery(window).off('beforeunload');
            var origOn = jQuery.fn.on;
            jQuery.fn.on = function() {
                if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].indexOf('beforeunload') !== -1) return this;
                return origOn.apply(this, arguments);
            };
        });
    }
    try {
        Object.defineProperty(window, 'onbeforeunload', { get: function() { return null; }, set: function() {}, configurable: false });
    } catch(e) {}

    // Size the root element to exactly fill the viewport between Unraid header and footer.
    // Without this, iframes tell xterm.js the terminal is taller than visible,
    // causing CLI apps to render content off-screen or behind the Unraid footer.
    function sizeRoot() {
        var el = document.getElementById('aicliagents-root');
        if (!el) return;
        var top = Math.max(0, el.getBoundingClientRect().top);
        // Detect Unraid footer height (status bar: <footer id="footer">)
        var footer = document.getElementById('footer');
        var footerH = footer ? footer.offsetHeight : 0;
        var h = Math.max(400, window.innerHeight - top - footerH);
        el.style.height = h + 'px';
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sizeRoot);
    else setTimeout(sizeRoot, 0);
    window.addEventListener('resize', sizeRoot);
    setTimeout(sizeRoot, 500);
})();
</script>
