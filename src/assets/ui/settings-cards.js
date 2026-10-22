/**
 * <module_context>
 * Description: The cards of the Settings page. aicliCards.attach(root) finds every
 *   [data-aicli-cards] list in root, reads the saved layout of that tab, and wires the
 *   shared card header: the collapse button, the [more] menu (Move up, Move down, Move to
 *   top, Collapse all, Expand all, Reset layout), the card order (menu, Alt+Arrow keys and
 *   drag on a desktop), the inline "Saved" tick, and the one-line card status.
 *   Spec: docs/specs/SETTINGS_PAGE_FIRST_CLASS_REDESIGN.md, "Page model" and "Save model".
 * Dependencies: none (vanilla JS). The markup comes from src/includes/ui/SettingsCard.php
 *   (aicli_card_open, aicli_card_close). The column script (window.aicliConfigColumns, in
 *   ManagerConfigTab.php) runs again after each change.
 * Constraints:
 *   - The layout lives in the browser only. Key: aicli_settings_layout_v1. JSON:
 *     { "<tab>": { "order": [ids], "collapsed": { "<id>": true|false } } }.
 *     "collapsed" holds only the choices of the person. A card without a choice follows
 *     its default (open, except a card whose status says Off).
 *   - Every read and write of localStorage is in try/catch. A blocked store gives the
 *     default layout and never throws.
 *   - A card that holds a warning opens itself. That open state is never saved.
 *   - The state functions (loadLayout, saveLayout, toggleCard, resetLayout) are pure
 *     apart from the store, so a test can run them without a browser.
 *   - The order is one list of card ids per tab ("order"). An id that is not on the page is
 *     dropped. A card that is not in the list is put after the card that precedes it in the
 *     default order. The column script (aicliConfigColumns) takes the order from sortCards().
 *   - Cards are MOVED in the DOM, never cloned, so their handlers survive.
 *   - Tab keys (the value of data-aicli-cards): "config", "storage", "store" (the agent cards
 *     of the Agent Store, in #agent-store-grid), "hub" and "relay" (Phase 7). A tab without
 *     the column script puts its cards in order into the list itself. setOrder(list, ids)
 *     sets and saves a whole order (the Agent Store sort button).
 *   - Drag: pointer events on the [more] button, a desktop pointer only, after a 6 px move.
 *     The other cards do not move until the drop. Escape cancels.
 * </module_context>
 */
(function () {
    'use strict';

    var KEY = 'aicli_settings_layout_v1';
    var SAVED_MS = 2000;

    // ---------------------------------------------------------------- the store

    function readAll() {
        try {
            var raw = window.localStorage.getItem(KEY);
            var all = raw ? JSON.parse(raw) : {};
            return all && typeof all === 'object' && !Array.isArray(all) ? all : {};
        } catch (e) {
            return {};
        }
    }

    function writeAll(all) {
        try {
            if (Object.keys(all).length === 0) window.localStorage.removeItem(KEY);
            else window.localStorage.setItem(KEY, JSON.stringify(all));
            return true;
        } catch (e) {
            return false;
        }
    }

    function has(obj, key) {
        return Object.prototype.hasOwnProperty.call(obj, key);
    }

    // ---------------------------------------------------------------- pure state

    /** The saved order, without ids that no longer exist, with new ids at their default place. */
    function mergeOrder(saved, defaultOrder) {
        var out = [];
        var seen = {};
        (Array.isArray(saved) ? saved : []).forEach(function (id) {
            if (defaultOrder.indexOf(id) !== -1 && !has(seen, id)) { out.push(id); seen[id] = true; }
        });
        defaultOrder.forEach(function (id, i) {
            if (has(seen, id)) return;
            // Put the new card after the nearest earlier card of the default order.
            var at = 0;
            for (var k = i - 1; k >= 0; k--) {
                var pos = out.indexOf(defaultOrder[k]);
                if (pos !== -1) { at = pos + 1; break; }
            }
            out.splice(at, 0, id);
            seen[id] = true;
        });
        return out;
    }

    /**
     * Phase 5 renamed and regrouped the cards of the Configuration tab. A layout saved with the
     * old ids moves to the new ids. LEGACY_IDS maps an old id to the new card that took its
     * place. "voice", "notebook" and "ssh" keep their ids and are not listed. A saved id that is
     * neither a new id nor an old id is dropped later, by mergeOrder and by loadLayout.
     */
    var LEGACY_IDS = {
        'global': 'general',
        'session': 'terminal',
        'patterns': 'continue',
        'plugin-management': 'general'
    };

    /** The saved entry of one tab with the old ids replaced. A choice already made for a new id wins. */
    function migrateEntry(entry) {
        var e = entry && typeof entry === 'object' ? entry : {};
        var order = [];
        (Array.isArray(e.order) ? e.order : []).forEach(function (id) {
            var to = has(LEGACY_IDS, id) ? LEGACY_IDS[id] : id;
            if (order.indexOf(to) === -1) order.push(to);
        });
        var collapsed = {};
        var src = e.collapsed && typeof e.collapsed === 'object' ? e.collapsed : {};
        // New ids first, so that a choice for a new id is never replaced by an old one.
        Object.keys(src).forEach(function (id) {
            if (!has(LEGACY_IDS, id) && typeof src[id] === 'boolean') collapsed[id] = src[id];
        });
        Object.keys(src).forEach(function (id) {
            if (has(LEGACY_IDS, id) && typeof src[id] === 'boolean' && !has(collapsed, LEGACY_IDS[id])) collapsed[LEGACY_IDS[id]] = src[id];
        });
        return { order: order, collapsed: collapsed };
    }

    /**
     * defaults: { order: [ids], collapsed: { id: true } }. Returns
     * { order, collapsed, touched }. collapsed holds a value for every known card.
     * touched marks the cards where the person made a choice. Only those are saved.
     */
    function loadLayout(tab, defaults) {
        var d = defaults || {};
        var defaultOrder = Array.isArray(d.order) ? d.order.slice() : [];
        var defaultCollapsed = d.collapsed && typeof d.collapsed === 'object' ? d.collapsed : {};
        var entry = readAll()[tab];
        entry = entry && typeof entry === 'object' ? entry : {};
        if (tab === 'config') entry = migrateEntry(entry); // Phase 5: the old card ids of the Configuration tab
        var savedCollapsed = entry.collapsed && typeof entry.collapsed === 'object' ? entry.collapsed : {};
        var collapsed = {};
        var touched = {};
        defaultOrder.forEach(function (id) {
            if (has(savedCollapsed, id) && typeof savedCollapsed[id] === 'boolean') {
                collapsed[id] = savedCollapsed[id];
                touched[id] = true;
            } else {
                collapsed[id] = !!defaultCollapsed[id];
            }
        });
        return { order: mergeOrder(entry.order, defaultOrder), collapsed: collapsed, touched: touched };
    }

    /** Save the layout of one tab. Returns false when the store is blocked. */
    function saveLayout(tab, layout) {
        var all = readAll();
        var collapsed = {};
        var l = layout || {};
        var src = l.collapsed || {};
        Object.keys(src).forEach(function (id) {
            if (!l.touched || l.touched[id]) collapsed[id] = !!src[id];
        });
        all[tab] = { order: Array.isArray(l.order) ? l.order.slice() : [], collapsed: collapsed };
        return writeAll(all);
    }

    /** A new layout with the card flipped. An unknown id gives the same layout back. */
    function toggleCard(layout, id) {
        return setCollapsedIn(layout, id, !(layout.collapsed && layout.collapsed[id]));
    }

    function setCollapsedIn(layout, id, value) {
        if (!layout || !layout.collapsed || !has(layout.collapsed, id)) return layout;
        var collapsed = {};
        var touched = {};
        Object.keys(layout.collapsed).forEach(function (k) { collapsed[k] = layout.collapsed[k]; });
        Object.keys(layout.touched || {}).forEach(function (k) { touched[k] = true; });
        collapsed[id] = !!value;
        touched[id] = true;
        return { order: (layout.order || []).slice(), collapsed: collapsed, touched: touched };
    }

    /** A new order with the card moved by delta places. The ends stop it. An unknown id changes nothing. */
    function moveCard(order, id, delta) {
        var out = Array.isArray(order) ? order.slice() : [];
        var at = out.indexOf(id);
        if (at === -1) return out;
        var to = Math.max(0, Math.min(out.length - 1, at + (parseInt(delta, 10) || 0)));
        out.splice(at, 1);
        out.splice(to, 0, id);
        return out;
    }

    /** A new order with the card first. */
    function moveToTop(order, id) {
        return moveCard(order, id, -(Array.isArray(order) ? order.length : 0));
    }

    /** A new order with the card put before beforeId. beforeId null or unknown puts it last. */
    function moveBefore(order, id, beforeId) {
        var out = Array.isArray(order) ? order.slice() : [];
        var at = out.indexOf(id);
        if (at === -1 || id === beforeId) return out;
        out.splice(at, 1);
        var to = beforeId === null || beforeId === undefined ? -1 : out.indexOf(beforeId);
        if (to === -1) out.push(id);
        else out.splice(to, 0, id);
        return out;
    }

    /** A new layout with another order. The open state and the choices stay. */
    function withOrder(layout, order) {
        var l = layout || {};
        var collapsed = {};
        var touched = {};
        Object.keys(l.collapsed || {}).forEach(function (k) { collapsed[k] = l.collapsed[k]; });
        Object.keys(l.touched || {}).forEach(function (k) { touched[k] = true; });
        return { order: order.slice(), collapsed: collapsed, touched: touched };
    }

    function sameOrder(a, b) {
        if (a.length !== b.length) return false;
        for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
        return true;
    }

    /** Forget the saved layout of one tab. Returns false when the store is blocked. */
    function resetLayout(tab) {
        var all = readAll();
        delete all[tab];
        return writeAll(all);
    }

    // ---------------------------------------------------------------- the page

    function cardsOf(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[data-aicli-card]'));
    }

    function bodyOf(card) {
        return card.querySelector(':scope > .aicli-card-body');
    }

    function toggleBtnOf(card) {
        return card.querySelector(':scope > .aicli-card-head [data-aicli-card-toggle]');
    }

    function announce(text) {
        var live = document.getElementById('aicli-settings-live');
        if (!live) {
            live = document.createElement('div');
            live.id = 'aicli-settings-live';
            live.className = 'aicli-sr-only';
            live.setAttribute('aria-live', 'polite');
            live.setAttribute('aria-atomic', 'true');
            document.body.appendChild(live);
        }
        live.textContent = '';
        live.textContent = text;
    }

    /** Show or hide the body of one card. This does not save anything. */
    function paint(card, collapsed) {
        var btn = toggleBtnOf(card);
        var body = bodyOf(card);
        if (btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (body) body.hidden = !!collapsed;
        card.classList.toggle('aicli-card--collapsed', !!collapsed);
    }

    /** Only the Configuration list is laid out in columns. Any other list is one column in card order. */
    function usesColumns(list) {
        return typeof window.aicliConfigColumns === 'function' && listState(list).tab === 'config';
    }

    function runColumns() {
        if (typeof window.aicliConfigColumns === 'function') {
            try { window.aicliConfigColumns(); } catch (e) { /* the layout script must never block a toggle */ }
        }
    }

    /** The column script moves cards in the DOM. A moved focused button loses focus: give it back. */
    function relayout(focusEl) {
        runColumns();
        if (focusEl && document.activeElement !== focusEl && focusEl.isConnected) {
            try { focusEl.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
        }
    }

    function listState(list) {
        if (!list.__aicliCards) list.__aicliCards = { tab: list.getAttribute('data-aicli-cards') || 'config', layout: null, defaults: null };
        return list.__aicliCards;
    }

    /** The default place of a card. The column script or this script stamps it before any card moves. */
    function stampPositions(list) {
        cardsOf(list).forEach(function (card, i) {
            if (!card.hasAttribute('data-aicli-pos')) card.setAttribute('data-aicli-pos', String(i));
        });
    }

    function readDefaults(list) {
        var order = [];
        var collapsed = {};
        stampPositions(list);
        var all = cardsOf(list).map(function (card, i) {
            return { card: card, pos: parseInt(card.getAttribute('data-aicli-pos'), 10), i: i };
        });
        all.sort(function (a, b) { return (a.pos - b.pos) || (a.i - b.i); });
        all.forEach(function (item) {
            var card = item.card;
            var id = card.getAttribute('data-aicli-card');
            order.push(id);
            if (card.getAttribute('data-aicli-default') === 'closed') collapsed[id] = true;
        });
        return { order: order, collapsed: collapsed };
    }

    /** The order that shows now: the layout when attached, else the saved order of the tab. */
    function currentOrder(list) {
        var st = listState(list);
        if (st.layout) return st.layout.order;
        return loadLayout(st.tab, readDefaults(list)).order;
    }

    /** The column script calls this. It gives the cards back in the saved order. */
    function sortCards(list, cards) {
        var order = currentOrder(list);
        var rank = cards.map(function (card, i) {
            var at = order.indexOf(card.getAttribute('data-aicli-card'));
            return { card: card, at: at === -1 ? order.length : at, i: i };
        });
        rank.sort(function (a, b) { return (a.at - b.at) || (a.i - b.i); });
        return rank.map(function (r) { return r.card; });
    }

    function titleOf(card) {
        var t = card.querySelector(':scope > .aicli-card-head .aicli-card-title');
        return t ? t.textContent.trim() : card.getAttribute('data-aicli-card');
    }

    function idOf(card) {
        return card.getAttribute('data-aicli-card');
    }

    function reducedMotion() {
        try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; }
    }

    /** Enable or disable the Move items of every card menu: no Move up at the top, no Move down at the end. */
    function updateMenus(list) {
        var order = listState(list).layout.order;
        cardsOf(list).forEach(function (card) {
            var at = order.indexOf(idOf(card));
            var first = at <= 0;
            var last = at === -1 || at >= order.length - 1;
            [['move-up', first], ['move-top', first], ['move-down', last]].forEach(function (pair) {
                var b = card.querySelector(':scope > .aicli-card-menu [data-aicli-card-action="' + pair[0] + '"]');
                if (b) { b.disabled = pair[1]; b.setAttribute('aria-disabled', pair[1] ? 'true' : 'false'); }
            });
        });
    }

    /** Without the column script the cards go in order into the list itself. */
    function reorderInList(list) {
        var cards = cardsOf(list);
        var sorted = sortCards(list, cards);
        // Already in order: move nothing, so a focused button and an open menu stay as they are.
        if (sorted.every(function (card, i) { return card === cards[i]; })) return;
        sorted.forEach(function (card) { list.appendChild(card); });
    }

    /** Short 200 ms ease-out slide of the moved card only (none with reduced motion). */
    function slide(card, from) {
        if (!from || reducedMotion() || typeof card.getBoundingClientRect !== 'function') return;
        var to = card.getBoundingClientRect();
        var dx = from.left - to.left;
        var dy = from.top - to.top;
        if ((!dx && !dy) || (!to.width && !to.height)) return;
        card.style.transition = 'none';
        card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
        var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };
        raf(function () {
            card.classList.add('aicli-card--moving');
            card.style.transition = '';
            card.style.transform = '';
            setTimeout(function () { card.classList.remove('aicli-card--moving'); }, 260);
        });
    }

    /** Take a new order: save it, move the cards, update the menus. Returns false when nothing changed. */
    function applyOrder(list, card, order, focusEl, from) {
        var st = listState(list);
        if (sameOrder(st.layout.order, order)) return false;
        var before = from || (card && card.getBoundingClientRect ? card.getBoundingClientRect() : null);
        st.layout = withOrder(st.layout, order);
        persist(list);
        if (usesColumns(list)) runColumns();
        else reorderInList(list);
        updateMenus(list);
        if (focusEl && document.activeElement !== focusEl && focusEl.isConnected) {
            try { focusEl.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
        }
        if (card) slide(card, before);
        return true;
    }

    /**
     * Put the cards of one list in another order (the Agent Store sort button). ids is the new
     * order of card ids; an unknown id is ignored, and a card that is not in ids stays after the card
     * that precedes it in the default order (so pass every id).
     * The order is saved like a move. Returns false when nothing changed.
     */
    function setOrder(list, ids) {
        if (!list) return false;
        attachList(list);
        var st = listState(list);
        if (!st.layout) return false;
        return applyOrder(list, null, mergeOrder(Array.isArray(ids) ? ids : [], st.layout.order), null, null);
    }

    /** where: -1 (up), 1 (down) or 'top'. The live region says the result. */
    function moveBy(list, card, where, focusEl) {
        var st = listState(list);
        var id = idOf(card);
        var next = where === 'top' ? moveToTop(st.layout.order, id) : moveCard(st.layout.order, id, where);
        var title = titleOf(card);
        if (!applyOrder(list, card, next, focusEl)) {
            announce('Card ' + title + ' is already ' + (where === 1 ? 'last' : 'first') + '. Position ' + (st.layout.order.indexOf(id) + 1) + ' of ' + st.layout.order.length + '.');
            return false;
        }
        var word = where === 'top' ? 'to the top' : (where === 1 ? 'down' : 'up');
        announce('Card ' + title + ' moved ' + word + '. Position ' + (next.indexOf(id) + 1) + ' of ' + next.length + '.');
        return true;
    }

    function applyLayout(list) {
        var st = listState(list);
        cardsOf(list).forEach(function (card) {
            var id = card.getAttribute('data-aicli-card');
            var collapsed = !!(st.layout.collapsed && st.layout.collapsed[id]);
            // A card with a warning opens itself. The open state is not saved.
            if (card.classList.contains('aicli-card--warn')) collapsed = false;
            paint(card, collapsed);
        });
    }

    function persist(list) {
        var st = listState(list);
        saveLayout(st.tab, st.layout);
    }

    function setOne(list, card, collapsed, focusEl) {
        var st = listState(list);
        var id = card.getAttribute('data-aicli-card');
        st.layout = setCollapsedIn(st.layout, id, collapsed);
        persist(list);
        paint(card, collapsed);
        relayout(focusEl);
    }

    function setAll(list, collapsed, focusEl) {
        var st = listState(list);
        cardsOf(list).forEach(function (card) {
            var id = card.getAttribute('data-aicli-card');
            st.layout = setCollapsedIn(st.layout, id, collapsed);
            // A card with a warning stays open when the others close.
            paint(card, collapsed && !card.classList.contains('aicli-card--warn'));
        });
        persist(list);
        relayout(focusEl);
        announce(collapsed ? 'All cards collapsed.' : 'All cards expanded.');
    }

    function resetOne(list, focusEl) {
        var st = listState(list);
        resetLayout(st.tab);
        st.layout = loadLayout(st.tab, st.defaults);
        applyLayout(list);
        if (!usesColumns(list)) reorderInList(list);
        updateMenus(list);
        relayout(focusEl);
        announce('Layout reset. Cards are in their default state.');
    }

    function closeMenus(except) {
        var open = document.querySelectorAll('[data-aicli-card-menu][aria-expanded="true"]');
        for (var i = 0; i < open.length; i++) {
            if (open[i] === except) continue;
            open[i].setAttribute('aria-expanded', 'false');
            var m = document.getElementById(open[i].getAttribute('aria-controls'));
            if (m) m.hidden = true;
        }
    }

    function listOf(el) {
        return el.closest ? el.closest('[data-aicli-cards]') : null;
    }

    function onAction(list, action, trigger) {
        var menuBtn = null;
        var menu = trigger.closest ? trigger.closest('.aicli-card-menu') : null;
        if (menu) menuBtn = document.querySelector('[data-aicli-card-menu][aria-controls="' + menu.id + '"]');
        var focusEl = menuBtn || trigger;
        if (trigger.disabled) return;
        var card = trigger.closest ? trigger.closest('[data-aicli-card]') : null;
        if (card && action === 'move-up') moveBy(list, card, -1, focusEl);
        else if (card && action === 'move-down') moveBy(list, card, 1, focusEl);
        else if (card && action === 'move-top') moveBy(list, card, 'top', focusEl);
        else if (action === 'collapse-all') setAll(list, true, focusEl);
        else if (action === 'expand-all') setAll(list, false, focusEl);
        else if (action === 'reset-layout') resetOne(list, focusEl);
        closeMenus(null);
    }

    function bindCard(list, card) {
        if (card.__aicliCardBound) return;
        card.__aicliCardBound = true;
        var btn = toggleBtnOf(card);
        if (btn) {
            btn.addEventListener('click', function () {
                // The shown state decides: a card that a warning opened has a different saved state.
                setOne(list, card, btn.getAttribute('aria-expanded') === 'true', btn);
            });
        }
        var menuBtn = card.querySelector(':scope > .aicli-card-head [data-aicli-card-menu]');
        if (menuBtn) {
            menuBtn.addEventListener('click', function () {
                var menu = document.getElementById(menuBtn.getAttribute('aria-controls'));
                if (!menu) return;
                if (Date.now() - dragEndedAt < 400) return; // the click that ends a drag is not a menu click
                var opening = menu.hidden;
                updateMenus(list);
                closeMenus(menuBtn);
                menu.hidden = !opening;
                menuBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
            });
            menuBtn.addEventListener('pointerdown', function (e) { dragPress(list, card, menuBtn, e); });
        }
        // A warning source (for example the storage move box) opens its card while it shows.
        var sources = card.querySelectorAll('[data-aicli-warns-card]');
        Array.prototype.forEach.call(sources, function (src) {
            function check() {
                var shown = !src.hidden && src.textContent.trim() !== '';
                var head = src.querySelector('strong');
                setWarning(card.getAttribute('data-aicli-card'), shown, head ? head.textContent.trim() : '');
            }
            if (typeof MutationObserver !== 'undefined') {
                new MutationObserver(check).observe(src, { attributes: true, attributeFilter: ['hidden'], childList: true, characterData: true, subtree: true });
            }
            check();
        });
    }

    function attachList(list) {
        var st = listState(list);
        if (st.attached) return false;
        st.attached = true;
        st.defaults = readDefaults(list);
        st.layout = loadLayout(st.tab, st.defaults);
        cardsOf(list).forEach(function (card) { bindCard(list, card); });
        applyLayout(list);
        updateMenus(list);
        if (!usesColumns(list)) reorderInList(list);
        relayout(null);
        return true;
    }

    /**
     * A list whose cards are made later by a script (the Storage tab: one card for each home)
     * calls this after it adds, replaces or removes cards. New cards get their handlers and
     * the saved state; the choices already made in this page stay.
     */
    function refresh(list) {
        if (!list) return false;
        var st = listState(list);
        if (!st.attached) return attachList(list);
        var old = st.layout;
        var defaults = readDefaults(list);
        var next = loadLayout(st.tab, defaults);
        if (old) {
            Object.keys(old.touched || {}).forEach(function (id) {
                if (has(next.collapsed, id)) { next.collapsed[id] = !!old.collapsed[id]; next.touched[id] = true; }
            });
            // The saved order decides. A blocked store has none: then the order of this page stays.
            var entry = readAll()[st.tab];
            if (!(entry && Array.isArray(entry.order))) next.order = mergeOrder(old.order, defaults.order);
        }
        st.defaults = defaults;
        st.layout = next;
        cardsOf(list).forEach(function (card) { bindCard(list, card); });
        applyLayout(list);
        if (!usesColumns(list)) reorderInList(list);
        updateMenus(list);
        return true;
    }

    /** Change the default open state of a card that the person has not touched (for example System memory). */
    function setDefaultCollapsed(id, collapsed) {
        var card = cardById(id);
        var list = card ? listOf(card) : null;
        if (!list) return false;
        var st = listState(list);
        if (!st.layout || !has(st.layout.collapsed, id) || (st.layout.touched && st.layout.touched[id])) return false;
        st.layout.collapsed[id] = !!collapsed;
        if (st.defaults && st.defaults.collapsed) {
            if (collapsed) st.defaults.collapsed[id] = true; else delete st.defaults.collapsed[id];
        }
        card.setAttribute('data-aicli-default', collapsed ? 'closed' : 'open');
        paint(card, !!collapsed && !card.classList.contains('aicli-card--warn'));
        return true;
    }

    // ---------------------------------------------------------------- drag (desktop pointer)

    var DRAG_PX = 6;
    var drag = null;
    var dragEndedAt = 0;

    function coarsePointer(e) {
        if (e && e.pointerType === 'touch') return true;
        try { return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); } catch (e) { return false; }
    }

    /** Press on the [more] button. The drag starts only after a 6 px move. */
    function dragPress(list, card, handle, e) {
        if (drag || (e.button !== undefined && e.button !== 0) || coarsePointer(e)) return;
        drag = { list: list, card: card, handle: handle, x: e.clientX, y: e.clientY, active: false, target: null, before: true, id: e.pointerId };
        document.addEventListener('pointermove', dragMove);
        document.addEventListener('pointerup', dragUp);
        document.addEventListener('pointercancel', dragCancel);
    }

    function dragClear() {
        if (!drag) return;
        document.removeEventListener('pointermove', dragMove);
        document.removeEventListener('pointerup', dragUp);
        document.removeEventListener('pointercancel', dragCancel);
        drag.card.classList.remove('aicli-card--dragging');
        drag.card.style.transform = '';
        drag.list.classList.remove('aicli-cards--dragging');
        cardsOf(drag.list).forEach(function (c) { c.classList.remove('aicli-card--drop-before', 'aicli-card--drop-after'); });
        if (drag.active) {
            dragEndedAt = Date.now();
            try { if (drag.id !== undefined && drag.handle.releasePointerCapture) drag.handle.releasePointerCapture(drag.id); } catch (e) { /* ignore */ }
        }
        drag = null;
    }

    /** The card under the pointer, else the nearest one. before: the pointer is in its upper half. */
    function dropTarget(list, dragged, x, y) {
        var best = null;
        var bestD = Infinity;
        cardsOf(list).forEach(function (c) {
            if (c === dragged) return;
            var r = c.getBoundingClientRect();
            var inside = x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            var cx = r.left + r.width / 2;
            var cy = r.top + r.height / 2;
            var d = inside ? -1 : Math.sqrt((cx - x) * (cx - x) + (cy - y) * (cy - y));
            if (d < bestD) { bestD = d; best = { card: c, before: y < cy }; }
        });
        return best;
    }

    function dragMove(e) {
        if (!drag) return;
        var dx = e.clientX - drag.x;
        var dy = e.clientY - drag.y;
        if (!drag.active) {
            if (Math.sqrt(dx * dx + dy * dy) < DRAG_PX) return;
            drag.active = true;
            closeMenus(null);
            drag.list.classList.add('aicli-cards--dragging');
            drag.card.classList.add('aicli-card--dragging');
            try { if (drag.id !== undefined && drag.handle.setPointerCapture) drag.handle.setPointerCapture(drag.id); } catch (e) { /* ignore */ }
        }
        // The card follows the pointer as a translated ghost. No other card moves.
        drag.card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
        var t = dropTarget(drag.list, drag.card, e.clientX, e.clientY);
        cardsOf(drag.list).forEach(function (c) { c.classList.remove('aicli-card--drop-before', 'aicli-card--drop-after'); });
        drag.target = t ? t.card : null;
        drag.before = t ? t.before : true;
        if (t) t.card.classList.add(t.before ? 'aicli-card--drop-before' : 'aicli-card--drop-after');
    }

    function dragUp() {
        if (!drag) return;
        var d = drag;
        if (!d.active) { dragClear(); return; } // a click: the menu opens
        var from = d.card.getBoundingClientRect();
        var id = idOf(d.card);
        var order = listState(d.list).layout.order;
        var next = order;
        if (d.target) {
            var rest = order.filter(function (x) { return x !== id; });
            var at = rest.indexOf(idOf(d.target));
            var beforeId = at === -1 ? null : (d.before ? rest[at] : (rest[at + 1] === undefined ? null : rest[at + 1]));
            next = moveBefore(order, id, beforeId);
        }
        var card = d.card;
        var handle = d.handle;
        var list = d.list;
        dragClear();
        if (applyOrder(list, card, next, handle, from)) {
            announce('Card ' + titleOf(card) + ' moved. Position ' + (next.indexOf(id) + 1) + ' of ' + next.length + '.');
        }
    }

    function dragCancel() {
        if (drag) dragClear();
    }

    function bindDocument() {
        // One set of document handlers, also when the script is loaded twice.
        if (document.__aicliCardsDoc) return;
        document.__aicliCardsDoc = true;
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) return;
            var actionEl = t.closest('[data-aicli-card-action]');
            if (actionEl) {
                // A toolbar button sits outside the list: use the list of its own tab first (Phase 7).
                var tabEl = actionEl.closest('.aicli-tab-content');
                var list = listOf(actionEl) || (tabEl && tabEl.querySelector('[data-aicli-cards]')) || document.querySelector('[data-aicli-cards]');
                if (list && list.__aicliCards) onAction(list, actionEl.getAttribute('data-aicli-card-action'), actionEl);
                return;
            }
            // A press outside every menu closes the open menu.
            if (!t.closest('.aicli-card-menu') && !t.closest('[data-aicli-card-menu]')) closeMenus(null);
        });
        document.addEventListener('keydown', function (e) {
            if (drag && (e.key === 'Escape' || e.key === 'Esc')) {
                e.preventDefault();
                dragCancel();
                return;
            }
            if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && e.target && e.target.closest) {
                var mb = e.target.closest('[data-aicli-card-menu]');
                var cardEl = mb ? mb.closest('[data-aicli-card]') : null;
                var listEl = cardEl ? listOf(cardEl) : null;
                if (listEl && listEl.__aicliCards && listEl.__aicliCards.layout) {
                    e.preventDefault();
                    moveBy(listEl, cardEl, e.key === 'ArrowUp' ? -1 : 1, mb);
                }
                return;
            }
            if (e.key !== 'Escape' && e.key !== 'Esc') return;
            var open = document.querySelector('[data-aicli-card-menu][aria-expanded="true"]');
            if (!open) return;
            closeMenus(null);
            open.focus();
        });
    }

    function attach(root) {
        bindDocument();
        var scope = root || document;
        var lists = scope.querySelectorAll ? scope.querySelectorAll('[data-aicli-cards]') : [];
        var n = 0;
        for (var i = 0; i < lists.length; i++) if (attachList(lists[i])) n++;
        return n;
    }

    // ---------------------------------------------------------------- status and warning

    function cardById(id) {
        return document.querySelector('[data-aicli-card="' + id + '"]');
    }

    /** Put a new one-line status on a card. */
    function setStatus(id, text) {
        var card = cardById(id);
        var node = card ? card.querySelector(':scope > .aicli-card-head [data-aicli-card-status]') : null;
        if (!node) return false;
        node.__aicliBase = text;
        paintStatus(node);
        return true;
    }

    /** The shown status is the warning label (when there is one) and then the normal status. */
    function paintStatus(node) {
        var base = node.__aicliBase || '';
        node.textContent = node.__aicliWarn ? node.__aicliWarn + (base ? ' · ' + base : '') : base;
    }

    /**
     * Mark a card as one that holds a warning. It opens itself while the warning stands.
     * label is the short warning text that leads the status line.
     */
    function setWarning(id, on, label) {
        var card = cardById(id);
        if (!card) return false;
        var was = card.classList.contains('aicli-card--warn');
        card.classList.toggle('aicli-card--warn', !!on);
        var node = card.querySelector(':scope > .aicli-card-head [data-aicli-card-status]');
        if (node) {
            if (node.__aicliBase === undefined) node.__aicliBase = node.textContent;
            node.__aicliWarn = on ? (label || 'Needs attention') : '';
            paintStatus(node);
        }
        if (on && !was) {
            paint(card, false);
            relayout(null);
        }
        return true;
    }

    function optionText(el) {
        if (el.tagName === 'SELECT') {
            var o = el.options && el.options[el.selectedIndex];
            return o ? o.text : '';
        }
        return el.value;
    }

    /** Build the status text from data-aicli-status-format and the current form values. */
    function renderStatus(card, form) {
        var fmt = card.getAttribute('data-aicli-status-format');
        if (!fmt) return false;
        var scope = form || card.closest('form') || document;
        var text = fmt.replace(/\{([a-z_]+)\}/g, function (m, name) {
            var el = scope.querySelector('[name="' + name + '"]');
            // "Short (default): about 20 words" shows as "Short": only the first word group of a choice is status.
            return el ? optionText(el).replace(/\s*\(default\)/i, '').replace(/:.*$/, '').trim() : '';
        });
        return setStatus(card.getAttribute('data-aicli-card'), text);
    }

    // ---------------------------------------------------------------- the Saved tick

    /**
     * A silent save worked. Show a "Saved" tick next to the control for two seconds, update
     * the status of its card, and say "Saved" in the live region. No pop-up.
     */
    function saved(ctrl, form) {
        announce('Saved');
        if (!ctrl || !ctrl.parentNode) return null;
        var anchor = (ctrl.closest && ctrl.closest('.input-row')) || ctrl;
        var next = anchor.nextElementSibling;
        if (next && next.classList.contains('aicli-saved-tick')) {
            if (next.__aicliTimer) clearTimeout(next.__aicliTimer);
            if (next.parentNode) next.parentNode.removeChild(next);
        }
        var tick = document.createElement('span');
        tick.className = 'aicli-saved-tick';
        tick.setAttribute('data-testid', 'saved-tick');
        tick.setAttribute('aria-hidden', 'true');
        tick.textContent = '✓ Saved';
        anchor.parentNode.insertBefore(tick, anchor.nextSibling);
        tick.__aicliTimer = setTimeout(function () {
            if (tick.parentNode) tick.parentNode.removeChild(tick);
        }, SAVED_MS);
        var card = ctrl.closest ? ctrl.closest('[data-aicli-card]') : null;
        if (card) renderStatus(card, form || ctrl.form || null);
        return tick;
    }

    window.aicliCards = {
        KEY: KEY,
        loadLayout: loadLayout,
        migrateEntry: migrateEntry,
        LEGACY_IDS: LEGACY_IDS,
        saveLayout: saveLayout,
        toggleCard: toggleCard,
        resetLayout: resetLayout,
        moveCard: moveCard,
        moveToTop: moveToTop,
        moveBefore: moveBefore,
        sortCards: sortCards,
        attach: attach,
        setOrder: setOrder,
        refresh: refresh,
        setDefaultCollapsed: setDefaultCollapsed,
        setStatus: setStatus,
        setWarning: setWarning,
        renderStatus: renderStatus,
        saved: saved,
        announce: announce
    };

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { attach(document); });
        else attach(document);
    }
})();
