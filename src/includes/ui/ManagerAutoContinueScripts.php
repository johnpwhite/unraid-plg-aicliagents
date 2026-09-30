<?php
/**
 * <module_context>
 * Description: Settings > Auto-continue patterns (docs/specs/AUTO_CONTINUE_PATTERNS.md).
 *              Lists the built-in patterns (read-only, per agent and kind) and the
 *              operator's own patterns (add / edit / enable / delete, each change
 *              saved at once), the pattern editor dialog with "Test against a
 *              workspace screen", and the GitHub report preview dialog. Vanilla DOM +
 *              fetch() (the ManagerHubScripts precedent). AJAX: get_autocontinue_patterns,
 *              save_autocontinue_pattern, set_autocontinue_pattern_enabled,
 *              delete_autocontinue_pattern, test_autocontinue_pattern,
 *              build_autocontinue_report (AutoContinueHandler).
 * Dependencies: window.csrf_token (ManagerGlobalState), ManagerConfigTab.php (#acp-card).
 * Constraints: every user or screen string is set with textContent, never parsed as HTML;
 *              no control has a `name`, so the settings form never saves them;
 *              the report link is a real <a target=_blank> whose href is the last
 *              URL the server built (masking and the length limit stay server-side).
 * </module_context>
 */
?>
<script>
(function () {
    'use strict';

    var ACP_KIND_ORDER = ['error', 'quota', 'busy', 'chrome'];
    var ACP_KIND_HINT = {
        error: 'A temporary provider error after which the agent stops at its prompt. The expression must start with ^ (it is matched at the start of a line, after borders and bullets).',
        quota: 'A usage quota that shows when to retry. Put the retry time in a capture group, for example (?<retry>\\d+h\\s*\\d+m) or (?<retry>\\d{1,2}(?::\\d\\d)?\\s*[ap]m). A Continue follows one minute after that time.',
        busy: 'A marker that the agent is still working or retrying, so no Continue is planned or sent.',
        chrome: 'A status or footer line that can show below an error without being new output.'
    };
    var acp = { patterns: [], builtins: [], agents: [], workspaces: [], kinds: {}, errors: [] };
    var acpOpener = null, acpReportOpener = null, acpReportTimer = null, acpReportSeq = 0;

    function acpAjax(action, body) {
        var csrf = (window.csrf_token || '');
        var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf);
        var postBody = Object.assign({}, body || {}, { csrf_token: csrf });
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(postBody).toString()
        }).then(function (r) { return r.json(); }).catch(function () { return { status: 'error', message: 'The server did not answer.' }; });
    }

    function el(tag, cls, text, attrs) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        return e;
    }
    function clear(n) { while (n && n.firstChild) n.removeChild(n.firstChild); }
    function btn(text, cls, attrs) { var b = el('button', 'aicli-btn-slim ' + (cls || ''), text, attrs); b.type = 'button'; return b; }
    function agentLabel(id) {
        if (id === '*') return 'All agents';
        for (var i = 0; i < acp.agents.length; i++) if (acp.agents[i].id === id) return acp.agents[i].name;
        return id;
    }
    function kindLabel(k) { return acp.kinds[k] || k; }
    function setStatus(node, text, isError) {
        if (!node) return;
        node.textContent = text || '';
        node.classList.toggle('acp-err', !!isError);
        if (isError) node.setAttribute('role', 'alert'); else node.removeAttribute('role');
    }

    // ---------------- list ----------------

    window.acpLoad = function () {
        var card = document.getElementById('acp-card');
        if (!card) return Promise.resolve();
        return acpAjax('get_autocontinue_patterns').then(function (res) {
            if (!res || res.status !== 'ok') { setStatus(document.getElementById('acp-status'), (res && res.message) || 'Could not load the patterns.', true); return; }
            acp.patterns = res.patterns || []; acp.builtins = res.builtins || []; acp.agents = res.agents || [];
            acp.workspaces = res.workspaces || []; acp.kinds = res.kinds || {}; acp.errors = res.errors || [];
            acpRenderList();
            acpRenderBuiltins();
            setStatus(document.getElementById('acp-status'), acp.errors.join(' '), acp.errors.length > 0);
        });
    };

    function acpRenderList() {
        var list = document.getElementById('acp-list');
        if (!list) return;
        clear(list);
        if (!acp.patterns.length) {
            list.appendChild(el('li', 'acp-empty', 'No patterns of your own yet. The built-in patterns below always apply.'));
            return;
        }
        acp.patterns.forEach(function (p) {
            var li = el('li', 'acp-row', null, { 'data-id': p.id });
            var main = el('div', 'acp-row-main');
            main.appendChild(el('strong', 'acp-row-name', p.name));
            main.appendChild(el('span', 'acp-row-meta', agentLabel(p.agent) + ' · ' + kindLabel(p.kind) + (p.source === 'mcp' ? ' · added by an agent' : '')));
            main.appendChild(el('code', 'acp-row-re', p.re));
            li.appendChild(main);

            var actions = el('div', 'acp-row-actions');
            var tog = el('label', 'acp-toggle');
            var cb = el('input', null, null, { type: 'checkbox', id: 'acp-en-' + p.id, 'aria-label': 'Use pattern ' + p.name });
            cb.checked = !!p.enabled;
            tog.appendChild(cb);
            tog.appendChild(el('span', null, 'On'));
            actions.appendChild(tog);
            var edit = btn('Edit', 'acp-edit', { 'aria-label': 'Edit ' + p.name });
            var rep = btn('Report', 'acp-report', { 'aria-label': 'Report ' + p.name + ' to GitHub' });
            var del = btn('Delete', 'acp-delete hb-secondary', { 'aria-label': 'Delete ' + p.name });
            actions.appendChild(edit); actions.appendChild(rep); actions.appendChild(del);
            li.appendChild(actions);
            var st = el('div', 'acp-row-status', null, { 'aria-live': 'polite', id: 'acp-row-status-' + p.id });
            li.appendChild(st);

            cb.addEventListener('change', function () {
                setStatus(st, 'Saving…');
                acpAjax('set_autocontinue_pattern_enabled', { id: p.id, enabled: cb.checked ? '1' : '0' }).then(function (res) {
                    if (res && res.status === 'ok') { p.enabled = cb.checked; setStatus(st, cb.checked ? 'Saved: on.' : 'Saved: off.'); }
                    else { cb.checked = !cb.checked; setStatus(st, 'Not saved: ' + ((res && res.message) || 'unknown error'), true); }
                });
            });
            edit.addEventListener('click', function () { acpOpenEditor(p, edit); });
            rep.addEventListener('click', function () { acpOpenReport(p.id, rep); });
            del.addEventListener('click', function () { acpConfirmDelete(p, li, st, del); });
            list.appendChild(li);
        });
    }

    function acpConfirmDelete(p, li, st, delBtn) {
        if (li.querySelector('.acp-confirm')) return;
        var box = el('div', 'acp-confirm', null, { role: 'group', 'aria-label': 'Confirm delete' });
        box.appendChild(el('span', null, 'Delete this pattern?'));
        var yes = btn('Yes, delete', 'acp-confirm-yes');
        var no = btn('No', 'acp-confirm-no hb-secondary');
        box.appendChild(yes); box.appendChild(no);
        li.appendChild(box);
        no.focus();
        no.addEventListener('click', function () { box.remove(); delBtn.focus(); });
        yes.addEventListener('click', function () {
            yes.disabled = true;
            acpAjax('delete_autocontinue_pattern', { id: p.id }).then(function (res) {
                if (res && res.status === 'ok') {
                    acp.patterns = acp.patterns.filter(function (x) { return x.id !== p.id; });
                    acpRenderList();
                    setStatus(document.getElementById('acp-status'), 'Deleted "' + p.name + '".');
                    var add = document.getElementById('acp-add'); if (add) add.focus();
                } else {
                    box.remove();
                    setStatus(st, 'Not deleted: ' + ((res && res.message) || 'unknown error'), true);
                }
            });
        });
    }

    function acpRenderBuiltins() {
        var root = document.getElementById('acp-builtin-list');
        if (!root) return;
        clear(root);
        var byAgent = {};
        acp.builtins.forEach(function (r) { (byAgent[r.agent] = byAgent[r.agent] || []).push(r); });
        var agents = Object.keys(byAgent).sort(function (a, b) { return a === '*' ? -1 : b === '*' ? 1 : agentLabel(a).localeCompare(agentLabel(b)); });
        agents.forEach(function (a) {
            var sec = el('section', 'acp-bi-agent', null, { 'data-agent': a });
            sec.appendChild(el('h4', 'acp-bi-h', agentLabel(a)));
            ACP_KIND_ORDER.forEach(function (k) {
                var rows = byAgent[a].filter(function (r) { return r.kind === k; });
                if (!rows.length) return;
                var grp = el('div', 'acp-bi-kind', null, { 'data-kind': k });
                grp.appendChild(el('div', 'acp-bi-kind-h', kindLabel(k)));
                var ul = el('ul', 'acp-bi-rules');
                rows.forEach(function (r) {
                    var li = el('li');
                    li.appendChild(el('span', 'acp-bi-id', r.id));
                    li.appendChild(el('code', 'acp-bi-re', r.re));
                    ul.appendChild(li);
                });
                grp.appendChild(ul);
                sec.appendChild(grp);
            });
            root.appendChild(sec);
        });
    }

    // ---------------- dialogs (shared) ----------------

    function acpTrapKeys(dialog, onClose) {
        dialog.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onClose(); return; }
            if (e.key !== 'Tab') return;
            var f = Array.prototype.filter.call(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
                function (x) { return !x.disabled && x.offsetParent !== null; });
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog.querySelector('[tabindex="-1"]'))) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    }

    function acpShell(id, titleId, title) {
        var back = el('div', 'hb-backdrop acp-backdrop', null, { id: id + '-backdrop' });
        var dlg = el('div', 'hb-dialog acp-dialog', null, { id: id, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId });
        var head = el('div', 'hb-header');
        var h = el('h2', 'hb-title', title, { id: titleId, tabindex: '-1' });
        var x = el('button', 'acp-x', '×', { type: 'button', 'aria-label': 'Close', id: id + '-x' });
        head.appendChild(h); head.appendChild(x);
        var body = el('div', 'hb-body acp-dbody');
        var foot = el('div', 'hb-footer acp-footer');
        dlg.appendChild(head); dlg.appendChild(body); dlg.appendChild(foot);
        back.appendChild(dlg);
        document.body.appendChild(back);
        return { back: back, dlg: dlg, body: body, foot: foot, title: h, x: x };
    }

    function field(labelText, control, hintText) {
        var wrap = el('div', 'hb-field acp-field');
        var lab = el('label', 'hb-label', labelText, { 'for': control.id });
        wrap.appendChild(lab);
        wrap.appendChild(control);
        if (hintText != null) wrap.appendChild(el('div', 'hb-help acp-hint', hintText, { id: control.id + '-hint' }));
        return wrap;
    }

    // ---------------- editor ----------------

    function acpCloseEditor() {
        var b = document.getElementById('acp-dialog-backdrop');
        if (b) b.remove();
        if (acpOpener && document.body.contains(acpOpener)) acpOpener.focus();
        else { var add = document.getElementById('acp-add'); if (add) add.focus(); }
        acpOpener = null;
    }

    window.acpOpenEditor = function (pattern, opener) {
        acpCloseEditor();
        acpOpener = opener || document.getElementById('acp-add');
        var p = pattern || { id: '', name: '', agent: '*', kind: 'error', re: '', sample: '' };
        var s = acpShell('acp-dialog', 'acp-title', p.id ? 'Edit pattern' : 'Add pattern');

        var name = el('input', null, null, { type: 'text', id: 'acp-name', maxlength: '60', autocomplete: 'off' });
        name.value = p.name || '';
        var agent = el('select', null, null, { id: 'acp-agent' });
        agent.appendChild(el('option', null, 'All agents', { value: '*' }));
        acp.agents.forEach(function (a) { agent.appendChild(el('option', null, a.name, { value: a.id })); });
        agent.value = p.agent || '*';
        var kind = el('select', null, null, { id: 'acp-kind', 'aria-describedby': 'acp-kind-hint' });
        ACP_KIND_ORDER.forEach(function (k) { kind.appendChild(el('option', null, kindLabel(k), { value: k })); });
        kind.value = p.kind || 'error';
        var re = el('input', 'acp-mono', null, { type: 'text', id: 'acp-re', autocomplete: 'off', spellcheck: 'false', placeholder: '^Provider error: \\d{3}' });
        re.value = p.re || '';
        var sample = el('textarea', 'acp-mono', null, { id: 'acp-sample', rows: '4', spellcheck: 'false' });
        sample.value = p.sample || '';

        s.body.appendChild(field('Name', name));
        var row = el('div', 'acp-two');
        row.appendChild(field('Agent', agent));
        row.appendChild(field('Kind', kind));
        s.body.appendChild(row);
        var kindHint = el('div', 'hb-help acp-hint', ACP_KIND_HINT[kind.value], { id: 'acp-kind-hint' });
        s.body.appendChild(kindHint);
        s.body.appendChild(field('Regular expression', re, 'PCRE. Write /body/flags, or only the body (it is saved as /body/iu).'));
        s.body.appendChild(field('Sample (the screen lines this is for)', sample, 'The expression must match the sample before it can be saved. Secrets in it are masked when it is saved.'));

        // Test against a workspace screen.
        var test = el('div', 'acp-testbox');
        var ws = el('select', null, null, { id: 'acp-ws' });
        if (!acp.workspaces.length) { ws.appendChild(el('option', null, 'No running workspace', { value: '' })); ws.disabled = true; }
        acp.workspaces.forEach(function (w) { ws.appendChild(el('option', null, w.name + ' (' + agentLabel(w.agentId) + ')', { value: w.id })); });
        var testBtn = btn('Test against a workspace screen', 'acp-test', { id: 'acp-test' });
        if (!acp.workspaces.length) testBtn.disabled = true;
        test.appendChild(field('Workspace', ws));
        test.appendChild(testBtn);
        var out = el('div', 'acp-test-out', null, { id: 'acp-test-out', 'aria-live': 'polite' });
        test.appendChild(out);
        s.body.appendChild(test);

        var err = el('div', 'hb-error acp-error', null, { id: 'acp-error' });
        var saved = el('div', 'hb-ok acp-saved', null, { id: 'acp-saved', 'aria-live': 'polite' });
        s.body.appendChild(err); s.body.appendChild(saved);

        var save = btn('Save', 'acp-save', { id: 'acp-save' });
        var report = btn('Report this pattern', 'acp-report-open', { id: 'acp-report-btn' });
        report.hidden = true;
        var close = btn('Close', 'hb-secondary', { id: 'acp-close' });
        s.foot.appendChild(report); s.foot.appendChild(save); s.foot.appendChild(close);

        kind.addEventListener('change', function () { kindHint.textContent = ACP_KIND_HINT[kind.value]; });
        s.x.addEventListener('click', acpCloseEditor);
        close.addEventListener('click', acpCloseEditor);
        s.back.addEventListener('mousedown', function (e) { if (e.target === s.back) acpCloseEditor(); });
        acpTrapKeys(s.dlg, acpCloseEditor);

        testBtn.addEventListener('click', function () {
            setStatus(out, 'Reading the screen…');
            testBtn.disabled = true;
            acpAjax('test_autocontinue_pattern', { re: re.value, kind: kind.value, workspaceId: ws.value }).then(function (res) {
                testBtn.disabled = false;
                clear(out);
                if (!res || res.status !== 'ok') { setStatus(out, (res && res.message) || 'The test failed.', true); return; }
                out.classList.remove('acp-err'); out.removeAttribute('role');
                var n = res.matched || 0;
                var sum = el('div', 'acp-test-sum', n === 0 ? 'No line on this screen matches.' : (n === 1 ? '1 line matches.' : n + ' lines match.'));
                if (res.retryText) sum.textContent += ' Retry time read: ' + res.retryText + '.';
                out.appendChild(sum);
                var ol = el('ol', 'acp-test-lines', null, { 'aria-label': 'Screen lines' });
                (res.lines || []).forEach(function (l) {
                    var li = el('li', l.match ? 'acp-hit' : null, l.text === '' ? ' ' : l.text);
                    if (l.match) { li.setAttribute('data-match', '1'); li.insertBefore(el('span', 'acp-sr', 'Match: '), li.firstChild); }
                    ol.appendChild(li);
                });
                out.appendChild(ol);
                var hit = ol.querySelector('.acp-hit');
                if (hit && hit.scrollIntoView) hit.scrollIntoView({ block: 'nearest' });
            });
        });

        save.addEventListener('click', function () {
            setStatus(err, ''); saved.textContent = '';
            save.disabled = true;
            acpAjax('save_autocontinue_pattern', { id: p.id || '', name: name.value, agent: agent.value, kind: kind.value, re: re.value, sample: sample.value, enabled: p.id ? (p.enabled ? '1' : '0') : '1' }).then(function (res) {
                save.disabled = false;
                if (!res || res.status !== 'ok') {
                    var msgs = (res && res.errors && res.errors.length) ? res.errors : [(res && res.message) || 'Not saved.'];
                    setStatus(err, 'Not saved: ' + msgs.join(' '), true);
                    return;
                }
                p = res.pattern;
                var i = -1;
                acp.patterns.forEach(function (x, k) { if (x.id === p.id) i = k; });
                if (i >= 0) acp.patterns[i] = p; else acp.patterns.push(p);
                re.value = p.re; sample.value = p.sample;
                acpRenderList();
                saved.textContent = 'Saved. The pattern is in use now. You can report it so it can become a built-in pattern.';
                report.hidden = false;
                s.title.textContent = 'Edit pattern';
            });
        });
        report.addEventListener('click', function () { if (p.id) acpOpenReport(p.id, report, ws.value); });

        s.title.focus();
    };

    // ---------------- report preview ----------------

    function acpCloseReport() {
        var b = document.getElementById('acp-report-backdrop');
        if (b) b.remove();
        if (acpReportTimer) { clearTimeout(acpReportTimer); acpReportTimer = null; }
        if (acpReportOpener && document.body.contains(acpReportOpener)) acpReportOpener.focus();
        acpReportOpener = null;
    }

    window.acpOpenReport = function (id, opener, workspaceId) {
        acpCloseReport();
        acpReportOpener = opener || null;
        var s = acpShell('acp-report', 'acpr-heading', 'Report this pattern');
        s.body.appendChild(el('p', 'hb-help acpr-note', 'This opens a new issue on GitHub (johnpwhite/unraid-plg-aicliagents) in a new tab, filled in from the fields below. Nothing is sent until you submit it there with your own GitHub login. Check the sample for private data first: paths under /mnt and token-like text are already masked.'));
        var title = el('input', null, null, { type: 'text', id: 'acpr-title', maxlength: '120', autocomplete: 'off' });
        var sample = el('textarea', 'acp-mono', null, { id: 'acpr-sample', rows: '5', spellcheck: 'false' });
        s.body.appendChild(field('Issue title', title));
        s.body.appendChild(field('Matched screen lines', sample, 'Only the lines the pattern matches. Edit them if needed.'));
        var dl = el('dl', 'acpr-fields', null, { id: 'acpr-fields' });
        s.body.appendChild(dl);
        var trimmed = el('div', 'hb-help acpr-trimmed', null, { id: 'acpr-trimmed', 'aria-live': 'polite' });
        var err = el('div', 'hb-error', null, { id: 'acpr-error' });
        s.body.appendChild(trimmed); s.body.appendChild(err);

        var open = el('a', 'aicli-btn-slim acpr-open', 'Open on GitHub', { id: 'acpr-open', target: '_blank', rel: 'noopener noreferrer', 'aria-disabled': 'true' });
        var close = btn('Close', 'hb-secondary', { id: 'acpr-close' });
        s.foot.appendChild(open); s.foot.appendChild(close);
        s.x.addEventListener('click', acpCloseReport);
        close.addEventListener('click', acpCloseReport);
        s.back.addEventListener('mousedown', function (e) { if (e.target === s.back) acpCloseReport(); });
        acpTrapKeys(s.dlg, acpCloseReport);
        open.addEventListener('click', function (e) { if (open.getAttribute('aria-disabled') === 'true') e.preventDefault(); });

        var labels = { agent: 'Agent', agent_version: 'Agent version', provider_model: 'Provider / model', kind: 'Kind', regex: 'Regular expression', retry_group: 'Retry-time group', plugin_version: 'Plugin version' };
        function build(edited) {
            var seq = ++acpReportSeq;
            open.setAttribute('aria-disabled', 'true'); open.removeAttribute('href');
            var body = { id: id, workspaceId: workspaceId || '' };
            if (edited) { body.title = title.value; body.sample = sample.value; }
            return acpAjax('build_autocontinue_report', body).then(function (res) {
                if (seq !== acpReportSeq) return;          // a newer edit is on its way
                if (!res || res.status !== 'ok') { setStatus(err, (res && res.message) || 'Could not build the report.', true); return; }
                setStatus(err, '');
                if (!edited) { title.value = res.title; sample.value = (res.fields && res.fields.sample) || ''; }
                clear(dl);
                Object.keys(labels).forEach(function (k) {
                    dl.appendChild(el('dt', null, labels[k]));
                    dl.appendChild(el('dd', k === 'regex' ? 'acp-mono' : null, (res.fields && res.fields[k]) || ''));
                });
                trimmed.textContent = res.trimmed ? 'The sample was shortened to fit the length GitHub accepts in a link. The expression is complete.' : '';
                open.setAttribute('href', res.url);
                open.setAttribute('aria-disabled', 'false');
            });
        }
        function later() {
            if (acpReportTimer) clearTimeout(acpReportTimer);
            open.setAttribute('aria-disabled', 'true'); open.removeAttribute('href');
            acpReportTimer = setTimeout(function () { acpReportTimer = null; build(true); }, 350);
        }
        title.addEventListener('input', later);
        sample.addEventListener('input', later);
        s.title.focus();
        return build(false);
    };

    function acpInit() {
        var add = document.getElementById('acp-add');
        if (!add || add.getAttribute('data-bound')) return;
        add.setAttribute('data-bound', '1');
        add.addEventListener('click', function () { acpOpenEditor(null, add); });
        acpLoad();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', acpInit); else acpInit();
})();
</script>
