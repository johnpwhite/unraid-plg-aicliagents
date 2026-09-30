<?php
/**
 * <module_context>
 * Description: Core JavaScript logic for AICliAgents Terminal (resizing, logging, workspaces).
 * Dependencies: jQuery, TerminalGlobalState, TerminalUploadScripts.
 * Constraints: Atomic UI fragment (small; resizing + logging only).
 * </module_context>
 */
?>
<script>
(function() {
    // This script owns the terminal root's height (touch lock, keyboard pin).
    // TerminalGlobalState's older sizeRoot() stands down when it sees this.
    window.aicliRootSizeOwner = 'TerminalScripts';
    window.aicli_log_to_server = function(message, level = 2) {
        // Unraid's web server checks csrf_token in the POST BODY; a token in
        // the URL alone is rejected with no error, and the line is lost
        // (2026-09-23: every touch/keyboard diagnostic from a phone was lost).
        fetch('/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + window.csrf_token, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ message: message, level: level, csrf_token: window.csrf_token || '' })
        }).catch(function() { /* diagnostics only */ });
    };

    function suppressScroll() {
        const targets = 'html, body, #displaybox, #sb-body, #sb-container, #main-content, .main-section';
        // overscroll-behavior: the page itself must never pull to refresh
        // or rubber-band around the terminal (#263).
        $(targets).css({ 'overflow': 'hidden', 'overflow-y': 'hidden', 'overscroll-behavior': 'none', 'scrollbar-gutter': 'none', 'width': '100%', 'max-width': '100%', 'margin': '0', 'padding': '0' });
        document.documentElement.style.setProperty('overflow', 'hidden', 'important');
        document.body.style.setProperty('overflow', 'hidden', 'important');
    }

    let resizeTimer;
    let rootSized = false;
    let measuredWidth = 0;
    let lockedHeight = 0;
    let keyboardOpen = false;
    // A keyboard covers far more than the browser toolbar ever moves.
    const KEYBOARD_MIN_PX = 150;
    const touchViewport = ('ontouchstart' in window) || ((navigator.maxTouchPoints || 0) > 0);

    // iOS Chrome/WebKit emits resize events while its address bar is being
    // pulled down or hidden. Recomputing the host height for those transient
    // visual changes makes the terminal chase the footer and resize under a
    // two-finger page gesture. On touch devices the first settled layout is
    // therefore the terminal's fixed page size; a changed width or an explicit
    // orientation change is a real layout change and may remeasure it.
    function resizeRoot(force) {
        if (rootSized && touchViewport && !force && Math.abs(window.innerWidth - measuredWidth) <= 2) return;
        if (resizeTimer) cancelAnimationFrame(resizeTimer);
        resizeTimer = requestAnimationFrame(function() {
            const el = document.getElementById('aicliagents-root');
            if (!el) return;
            // Measure from the root's normal place, not from a keyboard pin.
            if (savedInline) unpin(el);
            const available = window.innerHeight - el.getBoundingClientRect().top - 24;
            lockedHeight = Math.max(400, Math.floor(available));
            keyboardOpen = false;
            el.style.height = lockedHeight + 'px';
            rootSized = true;
            measuredWidth = window.innerWidth;
            suppressScroll();
            notifyResize();
        });
    }

    function notifyResize() {
        window.dispatchEvent(new Event('resize'));
        document.querySelectorAll('iframe').forEach(function(f) { try { f.contentWindow.dispatchEvent(new Event('resize')); } catch(e) {} });
    }

    // Phone keyboard (#263, redesign 2026-09-23): iOS keeps innerHeight when
    // the on-screen keyboard opens and shrinks only the visual viewport, so
    // the prompt and the touch key row end up under the keyboard. While the
    // keyboard is up, pin the terminal root to the visual viewport (fixed,
    // top = visualViewport.offsetTop, height = visualViewport.height): the
    // terminal then fills exactly the area above the keyboard, and the
    // Unraid header is covered. When the keyboard closes, the root returns
    // to its normal place and locked height. Toolbar movement changes the
    // visual viewport by far less than KEYBOARD_MIN_PX and is still ignored.
    // (First version, which only shrank the root in place, left the prompt
    // under the keyboard on a real iPhone.)
    // Every pinned property is set with !important: TerminalStyles.php gives
    // #aicliagents-root `position: relative !important` and a 400 px
    // min-height, which beat plain inline styles (the pin silently never
    // applied on a real iPhone, 2026-09-23).
    const PINNED_PROPS = ['position', 'top', 'left', 'right', 'width', 'height', 'min-height', 'z-index', 'margin', 'background'];
    let savedInline = null;
    let kbLogCount = 0;
    function logKeyboard(what, vv, el) {
        if (kbLogCount >= 20 || typeof window.aicli_log_to_server !== 'function') return;
        kbLogCount += 1;
        const r = el.getBoundingClientRect();
        window.aicli_log_to_server('[touch keyboard] ' + what + ' inner=' + window.innerHeight + ' vv=' + Math.round(vv.height) + ' base=' + Math.round(vvBaseline)
            + ' vvTop=' + Math.round(vv.offsetTop) + ' scrollY=' + Math.round(window.scrollY) + ' rootTop=' + Math.round(r.top)
            + ' rootH=' + Math.round(r.height), 2);
    }
    function pinToVisualViewport(el, vv) {
        if (!savedInline) {
            savedInline = {};
            PINNED_PROPS.forEach(function(p) {
                savedInline[p] = [el.style.getPropertyValue(p), el.style.getPropertyPriority(p)];
            });
        }
        el.style.setProperty('position', 'fixed', 'important');
        el.style.setProperty('top', Math.round(vv.offsetTop) + 'px', 'important');
        // SIDEBAR_THEME_LAYOUT.md: stay right of Unraid's fixed side menu
        // (0px on the top-menu themes).
        el.style.setProperty('left', 'var(--aicli-content-left, 0px)', 'important');
        el.style.setProperty('right', 'var(--aicli-content-right, 0px)', 'important');
        el.style.setProperty('width', 'auto', 'important');
        el.style.setProperty('height', Math.round(vv.height) + 'px', 'important');
        el.style.setProperty('min-height', '0', 'important');
        el.style.setProperty('z-index', '10001', 'important');
        el.style.setProperty('margin', '0', 'important');
        el.style.setProperty('background', '#000', 'important');
    }
    function unpin(el) {
        if (!savedInline) return;
        PINNED_PROPS.forEach(function(p) {
            el.style.removeProperty(p);
            if (savedInline[p][0]) el.style.setProperty(p, savedInline[p][0], savedInline[p][1]);
        });
        savedInline = null;
        el.style.height = lockedHeight + 'px';
    }
    // Keyboard detection compares the visual viewport with the TALLEST visual
    // viewport seen at this width, not with innerHeight: Chrome on iOS can
    // shrink innerHeight together with the visual viewport when the keyboard
    // opens, and then an innerHeight comparison never sees a keyboard (a real
    // iPhone, 2026-09-23). The baseline resets when the width changes.
    let vvBaseline = 0;
    let vvBaselineWidth = 0;
    let pinnedGeometry = '';
    function onVisualViewportResize() {
        const vv = window.visualViewport;
        if (!vv || !touchViewport || !rootSized) return;
        const el = document.getElementById('aicliagents-root');
        if (!el) return;
        if (Math.abs(vv.width - vvBaselineWidth) > 2) {
            vvBaselineWidth = vv.width;
            vvBaseline = Math.max(vv.height, keyboardOpen ? 0 : window.innerHeight);
            logKeyboard('baseline', vv, el);
        }
        if (!keyboardOpen) vvBaseline = Math.max(vvBaseline, vv.height);
        if (vvBaseline - vv.height >= KEYBOARD_MIN_PX) {
            const wasOpen = keyboardOpen;
            keyboardOpen = true;
            const geometry = Math.round(vv.offsetTop) + ':' + Math.round(vv.height);
            if (geometry === pinnedGeometry) return;
            pinnedGeometry = geometry;
            pinToVisualViewport(el, vv);
            if (!wasOpen) logKeyboard('open', vv, el);
            notifyResize();
        } else if (keyboardOpen) {
            pinnedGeometry = '';
            keyboardOpen = false;
            unpin(el);
            logKeyboard('closed', vv, el);
            notifyResize();
        }
    }

    function onWindowResize() {
        // Desktop browsers should continue to follow ordinary window resizing.
        // On touch browsers only a width change is meaningful here; height-only
        // changes are browser-toolbar/keyboard movement, not a new page size.
        if (!touchViewport || !rootSized || Math.abs(window.innerWidth - measuredWidth) > 2) resizeRoot(false);
    }

    function onOrientationChange() {
        // orientationchange can arrive just before innerWidth/innerHeight settle.
        resizeRoot(true);
        setTimeout(function() { resizeRoot(true); }, 50);
    }

    $(function() {
        resizeRoot();
        window.addEventListener('resize', onWindowResize);
        window.addEventListener('orientationchange', onOrientationChange);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', onVisualViewportResize);
            // iOS moves the visual viewport (offsetTop) as well as resizing it.
            window.visualViewport.addEventListener('scroll', onVisualViewportResize);
            // Do not depend on the browser sending these events while focus
            // is inside the terminal iframe: also check a few times a second
            // on touch devices. The check is cheap and changes nothing when
            // the geometry is the same.
            if (touchViewport) setInterval(onVisualViewportResize, 300);
        }
        [100, 500, 2000].forEach(function(ms) { setTimeout(resizeRoot, ms); });
        suppressScroll();
    });
})();
</script>
