/**
 * <module_context>
 * Description: The (i) help component of the Settings page. aicliHelp.attach(root)
 *   finds every [data-aicli-help] button in root and wires it to its help panel.
 *   Spec: docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, "Help behind an (i)".
 * Dependencies: none (vanilla JS). The markup comes from
 *   src/includes/ui/SettingsCard.php (aicli_help_btn, aicli_help_panel).
 * Constraints:
 *   - The panel is in the page flow, under the row. It is never a floating box.
 *   - Click, tap, Enter and Space toggle the panel and pin it open.
 *   - With a fine pointer (a mouse), hover opens the panel after 300 ms and
 *     leaving closes it, unless a click pinned it.
 *   - Escape closes the panel and returns focus to the (i).
 *   - A press outside closes a pinned panel.
 *   - One panel is open per card at a time (the card is the nearest .aicli-card).
 *   - The panel is found by the aria-controls id of the button. A missing panel
 *     leaves the button inert, and nothing throws.
 * </module_context>
 */
(function () {
    'use strict';

    var HOVER_MS = 300;

    function panelFor(btn) {
        var id = btn.getAttribute('aria-controls');
        return id ? document.getElementById(id) : null;
    }

    function cardOf(btn) {
        var card = btn.closest ? btn.closest('.aicli-card') : null;
        return card || document.body;
    }

    function stateOf(btn) {
        if (!btn.__aicliHelp) btn.__aicliHelp = { pinned: false, timer: null };
        return btn.__aicliHelp;
    }

    function isOpen(btn) {
        return btn.getAttribute('aria-expanded') === 'true';
    }

    function clearTimer(btn) {
        var st = stateOf(btn);
        if (st.timer) { clearTimeout(st.timer); st.timer = null; }
    }

    function close(btn) {
        var panel = panelFor(btn);
        clearTimer(btn);
        stateOf(btn).pinned = false;
        btn.setAttribute('aria-expanded', 'false');
        if (panel) panel.hidden = true;
    }

    function open(btn, pinned) {
        var panel = panelFor(btn);
        if (!panel) return;
        // One panel per card: close every other open panel of the same card.
        var card = cardOf(btn);
        var others = card.querySelectorAll('[data-aicli-help][aria-expanded="true"]');
        for (var i = 0; i < others.length; i++) {
            if (others[i] !== btn) close(others[i]);
        }
        clearTimer(btn);
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        stateOf(btn).pinned = !!pinned;
    }

    // A click, a tap, Enter and Space. A panel that hover opened becomes pinned.
    function toggle(btn) {
        if (isOpen(btn)) {
            if (!stateOf(btn).pinned) { stateOf(btn).pinned = true; return; }
            close(btn);
        } else {
            open(btn, true);
        }
    }

    function finePointer() {
        try {
            return !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches);
        } catch (e) {
            return false;
        }
    }

    function isMouse(e) {
        return !e || !e.pointerType || e.pointerType === 'mouse';
    }

    function bind(btn) {
        if (btn.__aicliHelpBound) return false;
        btn.__aicliHelpBound = true;
        if (!btn.hasAttribute('aria-expanded')) btn.setAttribute('aria-expanded', 'false');

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            toggle(btn);
        });
        // Enter and Space. preventDefault stops the click the browser would add,
        // so the panel toggles once. Firefox adds the Space click on keyup.
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                toggle(btn);
            }
        });
        btn.addEventListener('keyup', function (e) {
            if (e.key === ' ' || e.key === 'Spacebar') e.preventDefault();
        });
        btn.addEventListener('pointerenter', function (e) {
            if (!isMouse(e) || !finePointer() || isOpen(btn)) return;
            clearTimer(btn);
            stateOf(btn).timer = setTimeout(function () {
                stateOf(btn).timer = null;
                open(btn, false);
            }, HOVER_MS);
        });
        btn.addEventListener('pointerleave', function (e) {
            if (!isMouse(e)) return;
            clearTimer(btn);
            if (isOpen(btn) && !stateOf(btn).pinned) close(btn);
        });
        return true;
    }

    function onEscape(e) {
        if (e.key !== 'Escape' && e.key !== 'Esc') return;
        var openBtns = document.querySelectorAll('[data-aicli-help][aria-expanded="true"]');
        var refocus = null;
        for (var i = 0; i < openBtns.length; i++) {
            var btn = openBtns[i];
            var panel = panelFor(btn);
            var active = document.activeElement;
            if (active && (active === btn || (panel && panel.contains(active)))) refocus = btn;
            close(btn);
        }
        if (refocus) refocus.focus();
    }

    // A press outside the (i) and its panel closes the pinned panels.
    function onOutside(e) {
        var openBtns = document.querySelectorAll('[data-aicli-help][aria-expanded="true"]');
        for (var i = 0; i < openBtns.length; i++) {
            var btn = openBtns[i];
            var panel = panelFor(btn);
            var t = e.target;
            if (t && (btn.contains(t) || (panel && panel.contains(t)))) continue;
            if (stateOf(btn).pinned) close(btn);
        }
    }

    function bindDocument() {
        if (document.__aicliHelpDoc) return;
        document.__aicliHelpDoc = true;
        document.addEventListener('keydown', onEscape);
        document.addEventListener('pointerdown', onOutside);
    }

    function attach(root) {
        var scope = root || document;
        if (!scope.querySelectorAll) return 0;
        bindDocument();
        var list = scope.querySelectorAll('[data-aicli-help]');
        var n = 0;
        for (var i = 0; i < list.length; i++) {
            if (bind(list[i])) n++;
        }
        return n;
    }

    function closeAll(root) {
        var scope = root || document;
        var openBtns = scope.querySelectorAll('[data-aicli-help][aria-expanded="true"]');
        for (var i = 0; i < openBtns.length; i++) close(openBtns[i]);
    }

    window.aicliHelp = { attach: attach, closeAll: closeAll, HOVER_MS: HOVER_MS };

    function auto() { attach(document); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto);
    else auto();
})();
