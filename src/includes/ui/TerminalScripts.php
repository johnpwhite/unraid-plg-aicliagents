<?php
/**
 * <module_context>
 * Description: Core JavaScript logic for AICliAgents Terminal (resizing, logging, workspaces).
 * Dependencies: jQuery, TerminalGlobalState, TerminalUploadScripts.
 * Constraints: Atomic UI fragment (< 100 lines).
 * </module_context>
 */
?>
<script>
(function() {
    window.aicli_log_to_server = function(message, level = 2) {
        fetch('/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + window.csrf_token, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ message: message, level: level })
        });
    };

    function suppressScroll() {
        const targets = 'html, body, #displaybox, #sb-body, #sb-container, #main-content, .main-section';
        $(targets).css({ 'overflow': 'hidden', 'overflow-y': 'hidden', 'scrollbar-gutter': 'none', 'width': '100%', 'max-width': '100%', 'margin': '0', 'padding': '0' });
        document.documentElement.style.setProperty('overflow', 'hidden', 'important');
        document.body.style.setProperty('overflow', 'hidden', 'important');
    }

    let resizeTimer;
    function resizeRoot() {
        if (resizeTimer) cancelAnimationFrame(resizeTimer);
        resizeTimer = requestAnimationFrame(function() {
            const el = document.getElementById('aicliagents-root');
            if (!el) return;
            const available = window.innerHeight - el.getBoundingClientRect().top - 24;
            el.style.height = Math.max(400, Math.floor(available)) + 'px';
            suppressScroll();
            window.dispatchEvent(new Event('resize'));
            document.querySelectorAll('iframe').forEach(function(f) { try { f.contentWindow.dispatchEvent(new Event('resize')); } catch(e) {} });
        });
    }

    $(function() {
        resizeRoot();
        window.addEventListener('resize', resizeRoot);
        [100, 500, 2000].forEach(function(ms) { setTimeout(resizeRoot, ms); });
        suppressScroll();
    });
})();
</script>
