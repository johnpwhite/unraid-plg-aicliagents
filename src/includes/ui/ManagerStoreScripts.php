<?php
/**
 * <module_context>
 * Description: JavaScript logic for agent marketplace in AICliAgents Manager.
 * Dependencies: jQuery, SweetAlert, $csrf_token.
 * Constraints: Atomic UI fragment. Handles version picker, install, update badges.
 * </module_context>
 */
?>
<script>
// Visibility-based button hide/show preserves flex container height so the
// card footer doesn't collapse while the install progress panel is active.
function _hideButtons(el) { el.css({visibility: 'hidden', 'pointer-events': 'none'}); }
function _showButtons(el) { el.css({visibility: '', 'pointer-events': ''}); }

// --- Version Picker & Install ---

// Install a specific version (or latest if no version picker)
function installVersionAgent(id, btn, explicitVersion) {
    // Resolve version. explicitVersion wins over the picker — used by the
    // Upgrade / Update-to-vX.Y.Z buttons so the picker can't pin to the
    // already-installed version and cause a no-op reinstall. Passing the
    // empty string forces "@latest" on the backend.
    var select = document.getElementById('version-select-' + id);
    var version = typeof explicitVersion === 'string'
        ? explicitVersion
        : (select ? select.value : '');
    // Never forward sentinels — AgentRegistry defaults for never-installed,
    // not real versions — they 404 on npm / github_release / tarball.
    if (version === '0.0.0' || version === 'unknown' || version === 'installed') version = '';

    // v2 card uses .av2-card[data-installed]; legacy card uses .agent-item.installed
    var $card = $(btn).closest('.av2-card, .agent-item');
    var isUpdate = $card.attr('data-installed') === '1' || $card.hasClass('installed');

    // Determine action label by comparing against the installed version
    // (which we always read from the picker's data-installed attribute —
    // authoritative even when explicitVersion was passed).
    var installed = select ? (select.getAttribute('data-installed') || '') : '';
    var label = 'Install';
    if (isUpdate && version && installed && version !== installed) {
        var cmp = versionCompare(version, installed);
        label = cmp > 0 ? 'Upgrade' : (cmp < 0 ? 'Downgrade' : 'Reinstall');
    }
    // AGENT_STORE_CARD_HEADER.md: the new-version button, the Upgrade button and the card menu already know the kind.
    // The picker may not be loaded yet, so its data-installed can be empty.
    if (_av2PendingKind && _av2PendingKind.id === id) label = _av2PendingKind.kind === 'change' ? 'Downgrade' : 'Upgrade';

    // Fetch active sessions FIRST (single async jump), then show ONE
    // consolidated confirm modal covering both the downgrade warning and
    // the session-close notice. Nested swal() calls inside sweet-alert v1
    // callbacks race with each other — the second modal frequently fails
    // to open, killing the install flow silently. One modal = zero race.
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_sessions_for_agent&agentId='
              + encodeURIComponent(id) + '&csrf_token=' + csrf)
        .always(function(data) {
            // $.always receives the response on success and the xhr on fail;
            // defend against both shapes.
            var sessions = (data && data.sessions) ? data.sessions : [];
            // UPGRADE_WITHOUT_INTERRUPTION.md: when the agent installs beside the
            // running version, the operator's open sessions are irrelevant to
            // this decision — do not warn about them and do not offer to close
            // them. Default FALSE so an older backend, or any error, keeps the
            // cautious old behaviour rather than silently promising safety.
            var sideBySide = !!(data && data.side_by_side);
            var waitReason = (data && typeof data.wait_reason === 'string')
                ? data.wait_reason : '';
            // #827: an unused older version that this update removes to make room.
            var unusedNote = (data && typeof data.unused_version_note === 'string') ? data.unused_version_note : '';
            _showInstallConfirm(id, version, btn, label, sessions, sideBySide, waitReason, unusedNote);
        });
}

// Single consolidated confirm modal. Shows the appropriate title + body for
// install / upgrade / downgrade / reinstall. With active sessions the safe
// default queues the change; destructive force-close is an explicit opt-in.
function _showInstallConfirm(id, version, btn, label, sessions, sideBySide, waitReason, unusedNote) {
    // An install that cannot affect the operator's sessions must not ask them
    // about their sessions. Treat them as absent for every decision below.
    var relevantSessions = sideBySide ? [] : sessions;
    // AGENT_STORE_CARD_HEADER.md R8: an upgrade (and a change of version that the new-version button offers) opens the
    // update overlay: the release notes, what happens to the open workspaces, and the keep-a-copy choice (WP #964).
    var pend = (_av2PendingKind && _av2PendingKind.id === id) ? _av2PendingKind : null;
    _av2PendingKind = null;
    if (label === 'Upgrade' || (label === 'Downgrade' && pend && pend.kind === 'change')) {
        av2RenderUpdateOverlay({ id: id, version: version, sessions: relevantSessions, sideBySide: sideBySide, openCount: sessions.length,
                                 waitReason: waitReason, unusedNote: unusedNote || '', kind: label === 'Upgrade' ? 'update' : 'change', opener: pend ? pend.opener : btn });
        return;
    }
    var isInstall = !label || label === 'Install';
    var vLabel = version ? ('v' + version) : 'latest';
    var title, confirmText;
    if (isInstall) {
        title = 'Install ' + vLabel + '?';
        confirmText = 'Install';
    } else {
        title = label + ' to ' + vLabel + '?';
        confirmText = 'Yes, ' + label;
    }

    var body = '';
    if (label === 'Downgrade') {
        body += 'This will replace the current version with an older one.\n\n';
    }
    if (sideBySide && sessions.length > 0) {
        // Say plainly that nothing stops, and what to do to pick the new one up.
        body = '<div style="text-align:left;line-height:1.5">'
             + 'Installs alongside the version you are running, so your '
             + sessions.length + ' open workspace' + (sessions.length === 1 ? '' : 's')
             + ' will not be interrupted.<br><br>'
             + 'They keep the version they started with until you switch them over.'
             + '</div>';
        confirmText = isInstall ? 'Install' : ('Yes, ' + label);
    } else if (relevantSessions.length > 0) {
        var lines = relevantSessions.map(function(s) {
            var p = s.path || '<no workspace>';
            return '• ' + _escAttr(p) + '  (' + _escAttr((s.id || '').slice(0, 8)) + ')';
        }).join('<br>');
        body = '<div style="text-align:left;line-height:1.5">'
             + ((!sideBySide && waitReason)
                ? '<div style="margin-bottom:10px;color:#7a4b00"><b>Why this upgrade must wait:</b> '
                  + _escAttr(waitReason) + '</div>'
                : '')
             + relevantSessions.length + ' active session' + (relevantSessions.length === 1 ? '' : 's')
             + ' will remain running:<br>' + lines
             + '<br><br><b>The change will queue safely and start after all sessions close naturally.</b>'
             + '<label style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;cursor:pointer">'
             + '<input type="checkbox" id="aicli-force-upgrade" style="margin-top:2px">'
             + '<span>Force now: terminate these sessions; background subagents, monitors, and shell tasks will stop.</span>'
             + '</label></div>';
        confirmText = 'Queue change safely';
    } else if (isInstall) {
        body = 'Proceed with installation.';
    }

    // For an Install with nothing to decide, skip the modal entirely. A
    // side-by-side install with open workspaces still shows the one-line
    // reassurance above rather than nothing, so the operator learns the rule
    // once instead of wondering whether their sessions were at risk.
    if (isInstall && relevantSessions.length === 0 && !(sideBySide && sessions.length > 0)) {
        doInstall(id, version, btn, 0, null, false, sideBySide);
        return;
    }

    var hasBody = body !== '';
    swal({
        title: title,
        text: body,
        html: hasBody,
        type: relevantSessions.length > 0 ? 'warning' : (label === 'Downgrade' ? 'warning' : 'info'),
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        closeOnConfirm: true,
    }, function(confirmed) {
        if (!confirmed) return;
        var force = document.getElementById('aicli-force-upgrade');
        doInstall(id, version, btn, relevantSessions.length, null, !!(force && force.checked), sideBySide);
    });
}

// ---------------------------------------------------------------------------
// WP #964 (slice): pre-upgrade "keep a copy" overlay.
// Offers a rollback backup of the current version before the upgrade replaces
// it. Fetches a size + free-space estimate, lets the user pick a destination,
// and — when space is short — disables the backup while still allowing the
// upgrade to proceed (or be cancelled).
// ---------------------------------------------------------------------------
var _aicliBackupCtx = { agentId: null, timer: null };

function _escAttr(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function _fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
}

// Append a "<label><b>value</b>" pair to an element (DOM nodes — no innerHTML).
function _aicliReadoutSeg(el, label, value, first) {
    if (!first) el.appendChild(document.createTextNode(' · '));
    el.appendChild(document.createTextNode(label));
    var b = document.createElement('b');
    b.textContent = value;
    el.appendChild(b);
}

// Update the readout / note / toggle from an estimate response (or null on
// fetch failure). Insufficient space OR a non-absolute path disables the
// backup toggle — the confirm button still upgrades, just without a copy.
function _aicliRenderBackupEstimate(est) {
    var readout = document.getElementById('aicli-bk-readout');
    var note    = document.getElementById('aicli-bk-note');
    var toggle  = document.getElementById('aicli-bk-toggle');
    var dest    = document.getElementById('aicli-bk-dest');
    if (!readout || !note || !toggle) return;

    var destVal = dest ? dest.value.trim() : '';
    var absOk = destVal.charAt(0) === '/';

    if (!est || est.status !== 'ok') {
        readout.textContent = 'Could not estimate backup size.';
        note.style.color = 'var(--orange, #e68a00)';
        note.textContent = '⚠ Backup unavailable — you can still upgrade without one, or cancel.';
        toggle.checked = false; toggle.disabled = true;
        return;
    }

    readout.textContent = '';
    _aicliReadoutSeg(readout, 'Current version: ', _fmtBytes(est.current_size), true);
    _aicliReadoutSeg(readout, 'Upgrade needs ≈ ', _fmtBytes(est.required), false);
    _aicliReadoutSeg(readout, 'Free at destination: ', _fmtBytes(est.free), false);
    readout.appendChild(document.createTextNode(' (estimate includes 10% headroom)'));

    if (!absOk) {
        note.style.color = 'var(--orange, #e68a00)';
        note.textContent = '⚠ Enter an absolute path (starting with /) for the backup.';
        toggle.checked = false; toggle.disabled = true;
        return;
    }
    if (est.storage_format === 'unavailable') {
        note.style.color = 'var(--orange, #e68a00)';
        note.textContent = '⚠ No persisted current installation was found to back up.';
        toggle.checked = false; toggle.disabled = true;
        return;
    }
    if (!est.sufficient) {
        note.style.color = 'var(--orange, #e68a00)';
        note.textContent = '⚠ Not enough space here for a backup. You can upgrade without one, or cancel.';
        toggle.checked = false; toggle.disabled = true;
        return;
    }
    note.style.color = '';
    note.textContent = '';
    toggle.disabled = false;
}

function _aicliFetchBackupEstimate(dest, cb) {
    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=get_upgrade_backup_estimate'
            + '&agentId=' + encodeURIComponent(_aicliBackupCtx.agentId)
            + '&dest=' + encodeURIComponent(dest || '')
            + '&csrf_token=' + csrf;
    $.getJSON(url).always(function(r) {
        cb((r && r.status === 'ok') ? r : null);
    });
}

// Debounced re-estimate when the destination field changes (different volume
// → different free space). Called from the input's inline oninput handler.
function _aicliBackupDestChanged() {
    if (_aicliBackupCtx.timer) clearTimeout(_aicliBackupCtx.timer);
    _aicliBackupCtx.timer = setTimeout(function() {
        var dest = document.getElementById('aicli-bk-dest');
        _aicliFetchBackupEstimate(dest ? dest.value.trim() : '', _aicliRenderBackupEstimate);
    }, 400);
}

// ---------------------------------------------------------------------------
// AGENT_STORE_CARD_HEADER.md (#793): the update overlay and the card states.
// The overlay replaces the swal "Upgrade to vX?" keep-a-copy dialog and keeps every control of it.
// ---------------------------------------------------------------------------
var _av2Dropdowns = {};          // id -> the version-check answer of the card (versions, dates, channel)
var _av2PendingKind = null;      // {id, kind}: set by av2OpenUpdateOverlay; read by installVersionAgent
var _av2Overlay = null;          // the open overlay: {back, opener, onKey}
var _av2LastInstall = {};        // id -> the arguments of the last doInstall (Retry uses them)
var _av2HeaderNodes = {};        // id -> the version nodes of the header while the card shows "Installing"

function av2AgentName(id) {
    var t = document.querySelector('.av2-card[data-agent="' + id + '"] .aicli-card-title');
    return t ? t.textContent : id;
}

// Small DOM builder: av2h('div', 'class', 'text', {attr: value}). Text only, never markup.
function av2h(tag, cls, text, attrs) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
}

// The same size rule as BootSpaceService::sizeText and the Remove old versions dialog.
function av2FmtBytes(n) {
    n = Math.max(0, Number(n) || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    if (n < 10485760) return (n / 1048576).toFixed(1) + ' MB';
    if (n < 1073741824) return Math.round(n / 1048576) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
}

// One place that sets the state of a card: idle, queued, installing, failed or done. The chip strip and the
// progress view share one grid cell, so no state changes the height of the card (R1). The ids progress-<id>,
// bar-<id>, status-text-<id> and buttons-<id> stay.
function av2SetCardState(id, state, text, opts) {
    opts = opts || {};
    var card = document.getElementById('av2-card-' + id);
    var panel = document.getElementById('progress-' + id);
    if (!panel) return;
    var strip = card ? card.querySelector('.av2-strip') : null;
    var panels = document.getElementById('av2-panels-' + id);
    var status = document.getElementById('status-text-' + id);
    var bar = document.getElementById('bar-' + id);
    var extra = document.getElementById('extra-' + id);
    var versions = card ? card.querySelector('.av2-versions') : null;
    var buttons = document.getElementById('buttons-' + id);
    var busy = (state === 'queued' || state === 'installing' || state === 'done');

    panel.classList.toggle('active', busy || state === 'failed');
    panel.classList.toggle('failed', state === 'failed');
    if (strip) { if (busy || state === 'failed') strip.setAttribute('inert', ''); else strip.removeAttribute('inert'); }
    if (panels) {
        if (busy) panels.setAttribute('inert', ''); else panels.removeAttribute('inert');
        panels.classList.toggle('av2-locked', busy);
    }
    if (card) card.setAttribute('data-av2-state', state);
    if (status && typeof text === 'string') { status.textContent = text; status.title = text; }
    if (bar && typeof opts.progress === 'number') bar.style.width = opts.progress + '%';
    if (buttons) {
        buttons.style.visibility = busy ? 'hidden' : '';
        buttons.style.pointerEvents = busy ? 'none' : '';
    }
    // The header keeps the versions in view: while the card installs it says "Installing vX…".
    if (versions) {
        if (busy) {
            if (!_av2HeaderNodes[id]) {
                _av2HeaderNodes[id] = Array.prototype.slice.call(versions.childNodes);
                _av2HeaderNodes[id].forEach(function (n) { versions.removeChild(n); });
            }
            var v = opts.version || '';
            if (!v) { var nb = _av2HeaderNodes[id].filter(function (n) { return n.getAttribute && n.getAttribute('data-version'); })[0]; v = nb ? nb.getAttribute('data-version') : ''; }
            while (versions.firstChild) versions.removeChild(versions.firstChild);
            versions.appendChild(av2h('span', 'av2-installing', 'Installing' + (v ? ' v' + v : '') + '…'));
        } else if (_av2HeaderNodes[id]) {
            while (versions.firstChild) versions.removeChild(versions.firstChild);
            _av2HeaderNodes[id].forEach(function (n) { versions.appendChild(n); });
            delete _av2HeaderNodes[id];
        }
    }
    // Both foot links are disabled while the agent installs.
    if (card) {
        Array.prototype.forEach.call(card.querySelectorAll('.av2-foot-link'), function (b) {
            b.disabled = busy;
            b.setAttribute('aria-disabled', busy ? 'true' : 'false');
        });
    }
    if (extra) {
        Array.prototype.forEach.call(extra.querySelectorAll('.av2-fail-actions'), function (n) { n.parentNode.removeChild(n); });
        if (state === 'failed') {
            var box = av2h('span', 'av2-fail-actions');
            var retry = av2h('button', 'aicli-btn-slim', 'Retry', { type: 'button', 'data-testid': 'install-retry-' + id });
            retry.onclick = function () {
                var a = _av2LastInstall[id];
                av2SetCardState(id, 'idle');
                if (a) doInstall(id, a.version, null, a.sessions, null, a.force, a.sideBySide);
            };
            var dismiss = av2h('button', 'aicli-btn-slim', 'Dismiss', { type: 'button', 'data-testid': 'install-dismiss-' + id });
            dismiss.onclick = function () { av2SetCardState(id, 'idle', ''); };
            box.appendChild(retry); box.appendChild(dismiss);
            extra.appendChild(box);
        }
    }
}

// --- A small, safe Markdown subset for release notes: headings, lists, bold, code, https links. Text only. ---
function av2RenderInline(parent, text) {
    var re = /(\*\*[^*\n]+\*\*|`[^`\n]+`|\[[^\]\n]+\]\(https:\/\/[^)\s]+\))/g;
    var last = 0, m;
    while ((m = re.exec(text)) !== null) {
        if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
        var tok = m[0];
        if (tok.charAt(0) === '*') parent.appendChild(av2h('strong', null, tok.slice(2, -2)));
        else if (tok.charAt(0) === '`') parent.appendChild(av2h('code', null, tok.slice(1, -1)));
        else {
            var cut = tok.indexOf('](');
            parent.appendChild(av2h('a', null, tok.slice(1, cut), { href: tok.slice(cut + 2, -1), target: '_blank', rel: 'noopener noreferrer' }));
        }
        last = m.index + tok.length;
    }
    if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
}

function av2RenderMarkdown(container, text) {
    var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
    var list = null, code = null, para = null;
    function flush() { list = null; para = null; }
    lines.forEach(function (line) {
        if (/^```/.test(line)) {
            if (code) { container.appendChild(code); code = null; } else { flush(); code = av2h('pre', 'av2-notes-code'); }
            return;
        }
        if (code) { code.textContent += (code.textContent ? '\n' : '') + line; return; }
        var h = /^(#{1,6})\s+(.*)$/.exec(line);
        var li = /^\s*[-*+]\s+(.*)$/.exec(line);
        if (h) { flush(); var hd = av2h('h5', 'av2-notes-h'); av2RenderInline(hd, h[2]); container.appendChild(hd); }
        else if (li) {
            if (!list) { list = av2h('ul', 'av2-notes-list'); container.appendChild(list); para = null; }
            var item = av2h('li'); av2RenderInline(item, li[1]); list.appendChild(item);
        } else if (line.trim() === '') { flush(); }
        else {
            if (!para) { para = av2h('p', 'av2-notes-p'); container.appendChild(para); list = null; } else para.appendChild(document.createTextNode(' '));
            av2RenderInline(para, line.trim());
        }
    });
    if (code) container.appendChild(code);
}

// The release notes block: one <details> per version, newest first, the newest open. No notes: the link only.
function av2RenderReleaseNotes(container, r, changelogUrl) {
    while (container.firstChild) container.removeChild(container.firstChild);
    var notes = (r && r.notes) || [];
    var link = (r && r.changelog_url) || changelogUrl || '';
    if (!notes.length) {
        container.appendChild(av2h('p', 'av2-notes-none', 'The plugin has no release notes for this version.', { 'data-testid': 'update-notes-none' }));
    }
    notes.forEach(function (n, i) {
        var d = av2h('details', 'av2-notes-ver', null, { 'data-testid': 'update-notes-' + n.version });
        if (i === 0) d.open = true;
        d.appendChild(av2h('summary', null, 'v' + n.version + (n.date ? ' · ' + n.date : '')));
        var body = av2h('div', 'av2-notes-body');
        av2RenderMarkdown(body, n.body || '');
        if (n.truncated) body.appendChild(av2h('p', 'av2-notes-p', 'The rest is on the vendor’s site.'));
        d.appendChild(body);
        container.appendChild(d);
    });
    if (link && /^https:\/\//.test(link)) {
        var p = av2h('p', 'av2-notes-link');
        p.appendChild(av2h('a', null, notes.length ? 'Open all release notes on the vendor’s site ↗' : 'Open the release notes on the vendor’s site ↗', { href: link, target: '_blank', rel: 'noopener noreferrer', 'data-testid': 'update-notes-link' }));
        container.appendChild(p);
    }
}

function av2CloseOverlay() {
    var o = _av2Overlay;
    if (!o) return;
    _av2Overlay = null;
    document.removeEventListener('keydown', o.onKey, true);
    if (o.back.parentNode) o.back.parentNode.removeChild(o.back);
    if (_aicliBackupCtx.timer) { clearTimeout(_aicliBackupCtx.timer); _aicliBackupCtx.timer = null; }
    if (o.opener && document.body.contains(o.opener) && typeof o.opener.focus === 'function') o.opener.focus();
}

// Opened by the new-version button, the hover Upgrade button and the card menu. It reads the open workspaces
// of the agent first, so the overlay can say what happens to them.
function av2OpenUpdateOverlay(id, opener) {
    var badge = document.querySelector('.av2-card[data-agent="' + id + '"] .av2-newver');
    var src = (opener && opener.getAttribute && opener.getAttribute('data-version')) ? opener : badge;
    if (!src) return;
    var kind = src.getAttribute('data-kind') || 'update';
    var version = src.getAttribute('data-version') || '';
    _av2PendingKind = { id: id, kind: kind, opener: opener || null };
    installVersionAgent(id, opener, version);
}

function av2RenderUpdateOverlay(o) {
    av2CloseOverlay();
    var id = o.id, version = o.version, sessions = o.sessions || [], sideBySide = !!o.sideBySide, openCount = o.openCount || 0;
    var kind = o.kind || 'update';
    var name = av2AgentName(id);
    var card = document.querySelector('.av2-card[data-agent="' + id + '"]');
    var instEl = card ? card.querySelector('.av2-ver-installed') : null;
    var curVer = instEl ? instEl.textContent.replace(/^v/, '') : '';
    var dd = _av2Dropdowns[id] || {};
    var channel = dd.channel ? String(dd.channel).replace(/^./, function (c) { return c.toUpperCase(); }) : 'Stable';
    var released = '';
    (dd.versions || []).forEach(function (v) { if (v && v.version === version && v.date) released = v.date; });

    var back = av2h('div', 'hb-backdrop acp-backdrop', null, { id: 'av2-update-backdrop' });
    var dlg = av2h('div', 'hb-dialog acp-dialog av2-update-dialog', null, { id: 'av2-update-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'av2-update-title', 'data-testid': 'update-overlay' });
    var head = av2h('div', 'hb-header');
    var title = av2h('h2', 'hb-title', kind === 'change' ? 'Change ' + name + ' to v' + version : 'Update ' + name, { id: 'av2-update-title', tabindex: '-1' });
    var x = av2h('button', 'acp-x', '×', { type: 'button', 'aria-label': 'Close', id: 'av2-update-x' });
    head.appendChild(title); head.appendChild(x);
    var body = av2h('div', 'hb-body acp-dbody');
    var foot = av2h('div', 'hb-footer acp-footer');
    dlg.appendChild(head); dlg.appendChild(body); dlg.appendChild(foot);
    back.appendChild(dlg);

    // 2. The version line.
    var vl = av2h('div', 'av2-update-versions', null, { 'data-testid': 'update-versions' });
    vl.appendChild(document.createTextNode('Installed '));
    vl.appendChild(av2h('b', null, curVer ? 'v' + curVer : 'unknown', { id: 'av2-update-cur' }));
    vl.appendChild(document.createTextNode(' → New '));
    vl.appendChild(av2h('b', null, 'v' + version));
    body.appendChild(vl);
    body.appendChild(av2h('div', 'hb-muted', channel + ' channel' + (released ? ' · released ' + released : '')));

    // 3. What is new (not for a change of version: that is not an update).
    var notes = null;
    if (kind !== 'change') {
        var nh = av2h('div', 'av2-update-section');
        nh.appendChild(av2h('div', 'hb-label', 'What is new'));
        notes = av2h('div', 'av2-notes', null, { 'data-testid': 'update-notes', 'aria-live': 'polite' });
        notes.appendChild(av2h('p', 'hb-muted', 'Loading the release notes…'));
        nh.appendChild(notes);
        body.appendChild(nh);
    }

    // 4. Your open workspaces: the same rules as before, in plain words.
    var wsHtmlNeeded = (sideBySide && openCount > 0) || o.waitReason || o.unusedNote || sessions.length > 0;
    var force = null;
    if (wsHtmlNeeded) {
        var ws = av2h('div', 'av2-update-section', null, { 'data-testid': 'update-workspaces' });
        ws.appendChild(av2h('div', 'hb-label', 'Your open workspaces'));
        if (sideBySide && openCount > 0) {
            var one = openCount === 1;
            ws.appendChild(av2h('p', 'av2-notes-p', 'Installs alongside the version you are running. Your ' + openCount + ' open workspace' + (one ? ' is' : 's are')
                + ' not interrupted. ' + (one ? 'It keeps' : 'They keep') + ' v' + (curVer || 'the version they have') + ' until you switch '
                + (one ? 'it' : 'them') + ' over from ' + (one ? 'its' : 'their') + ' … menu (Switch to…).'));
        }
        if (sideBySide && o.unusedNote) {
            // #827: say that the unused oldest version goes, instead of a wait.
            ws.appendChild(av2h('p', 'av2-notes-p', o.unusedNote, { 'data-testid': 'update-unused-version' }));
        }
        if (!sideBySide && o.waitReason) {
            var wr = av2h('p', 'av2-notes-p'); wr.appendChild(av2h('b', null, 'Why this update must wait: ')); wr.appendChild(document.createTextNode(o.waitReason));
            ws.appendChild(wr);
        }
        if (sessions.length > 0) {
            ws.appendChild(av2h('p', 'av2-notes-p', sessions.length + ' active session' + (sessions.length === 1 ? '' : 's') + ' will remain running:'));
            var ul = av2h('ul', 'av2-notes-list');
            sessions.forEach(function (s) { ul.appendChild(av2h('li', null, (s.path || '<no workspace>') + '  (' + String(s.id || '').slice(0, 8) + ')')); });
            ws.appendChild(ul);
            ws.appendChild(av2h('p', 'av2-notes-p', 'The update queues and starts after these sessions close.'));
            var fl = av2h('label', 'hb-check-label');
            force = av2h('input', null, null, { type: 'checkbox', id: 'aicli-force-upgrade' });
            fl.appendChild(force);
            fl.appendChild(av2h('span', null, 'Force now: terminate these sessions; background subagents, monitors, and shell tasks will stop.'));
            ws.appendChild(fl);
        }
        body.appendChild(ws);
    }

    // 5. Keep a copy. The ids stay: the estimate code and the tests use them.
    var bk = av2h('div', 'av2-update-section');
    var bl = av2h('label', 'hb-check-label');
    var toggle = av2h('input', null, null, { type: 'checkbox', id: 'aicli-bk-toggle' });
    bl.appendChild(toggle);
    var bt = av2h('span'); bt.appendChild(document.createTextNode('Keep a copy of ')); bt.appendChild(av2h('b', null, curVer ? 'v' + curVer : 'the current version', { id: 'av2-bk-ver' }));
    bt.appendChild(document.createTextNode(' so you can roll back'));
    bl.appendChild(bt);
    bk.appendChild(bl);
    var det = av2h('details', 'acp-details');
    det.appendChild(av2h('summary', null, 'Where the copy goes'));
    var dest = av2h('input', 'swal-input-show', null, { type: 'text', id: 'aicli-bk-dest', 'aria-label': 'Backup destination' });
    dest.style.cssText = 'width:100%; box-sizing:border-box;';
    dest.addEventListener('input', _aicliBackupDestChanged);
    det.appendChild(dest);
    det.appendChild(av2h('div', 'hb-help', '', { id: 'aicli-bk-readout' }));
    bk.appendChild(det);
    bk.appendChild(av2h('div', 'hb-help', '', { id: 'aicli-bk-note' }));
    body.appendChild(bk);

    // 6. Channel: a read-only line, and a way to the Version chip, where the choices already are.
    var ch = av2h('div', 'hb-muted');
    ch.appendChild(document.createTextNode('Channel: ' + channel + ' · '));
    var pick = av2h('a', null, 'Pick another version or channel', { href: '#', 'data-testid': 'update-pick-other' });
    pick.addEventListener('click', function (e) {
        e.preventDefault();
        av2CloseOverlay();
        av2OpenCard(id);
        var chip = document.querySelector('.av2-card[data-agent="' + id + '"] .av2-chip[data-target="channel"]');
        if (chip && chip.getAttribute('aria-expanded') !== 'true') chip.click();
        if (chip) chip.focus();
    });
    ch.appendChild(pick);
    body.appendChild(ch);

    // 7. Footer.
    var closeBtn = av2h('button', 'aicli-btn-slim hb-secondary', 'Close', { type: 'button', 'data-testid': 'update-close' });
    var go = av2h('button', 'aicli-btn-slim hb-primary', '', { type: 'button', 'data-testid': 'update-now' });
    foot.appendChild(closeBtn); foot.appendChild(go);
    function goLabel() {
        var forced = !!(force && force.checked);
        if (kind === 'change') return sessions.length > 0 && !forced ? 'Queue change' : (forced ? 'Force change now' : 'Change version');
        return sessions.length > 0 && !forced ? 'Queue update' : (forced ? 'Force update now' : 'Update now');
    }
    go.textContent = goLabel();
    if (force) force.addEventListener('change', function () { go.textContent = goLabel(); });
    // Another browser tab started an update of this agent: the card is already in the installing state.
    if (card && /^(queued|installing|done)$/.test(card.getAttribute('data-av2-state') || '')) {
        body.insertBefore(av2h('p', 'av2-notes-p', 'An update of this agent is already running.', { role: 'status', 'data-testid': 'update-already-running' }), body.firstChild);
        go.disabled = true;
    }

    closeBtn.onclick = x.onclick = av2CloseOverlay;
    back.addEventListener('mousedown', function (e) { if (e.target === back) av2CloseOverlay(); });
    go.onclick = function () {
        var destEl = document.getElementById('aicli-bk-dest');
        // Backup only when the toggle is both checked and enabled: a disabled toggle means too little space or a bad path.
        var opts = (toggle.checked && !toggle.disabled) ? { backup: true, dest: (destEl ? destEl.value.trim() : '') } : null;
        var forced = !!(force && force.checked);
        av2CloseOverlay();
        doInstall(id, version, null, sessions.length, opts, forced, sideBySide);
    };

    var onKey = function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); e.stopPropagation(); av2CloseOverlay(); return; }
        if (e.key !== 'Tab') return;
        var f = Array.prototype.filter.call(dlg.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex="0"]'), function (n) { return !n.disabled && n.offsetParent !== null; });
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === title)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        else if (!dlg.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey, true);
    _av2Overlay = { back: back, opener: o.opener || null, onKey: onKey };
    document.body.appendChild(back);
    title.focus();

    // The backup estimate fills the folder and the sizes; the notes are fetched without blocking the dialog.
    _aicliBackupCtx.agentId = id;
    _aicliFetchBackupEstimate('', function (est) {
        if (_av2Overlay === null) return;
        var cv = (est && est.current_version) ? est.current_version : curVer;
        if (cv) {
            var c1 = document.getElementById('av2-update-cur'); if (c1) c1.textContent = 'v' + cv;
            var c2 = document.getElementById('av2-bk-ver'); if (c2) c2.textContent = 'v' + cv;
        }
        if (est && est.dest) dest.value = est.dest;
        _aicliRenderBackupEstimate(est);
    });
    if (notes) {
        var changelogUrl = card && card.querySelector('.av2-changelog-link') ? card.querySelector('.av2-changelog-link').getAttribute('href') : '';
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_release_notes&agentId=' + encodeURIComponent(id)
            + '&from=' + encodeURIComponent(curVer) + '&to=' + encodeURIComponent(version) + '&csrf_token=' + csrf)
            .done(function (r) { if (_av2Overlay !== null) av2RenderReleaseNotes(notes, (r && r.status === 'ok') ? r : null, changelogUrl); })
            .fail(function () { if (_av2Overlay !== null) av2RenderReleaseNotes(notes, null, changelogUrl); });
    }
}

// The card menu items that the Store adds (Update to vX…, Release notes) and their close.
document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-aicli-card-extra]') : null;
    if (!t) return;
    var id = t.getAttribute('data-agent') || '';
    var act = t.getAttribute('data-aicli-card-extra');
    var menu = t.closest('.aicli-card-menu');
    if (menu) {
        menu.hidden = true;
        var mb = document.querySelector('[data-aicli-card-menu][aria-controls="' + menu.id + '"]');
        if (mb) { mb.setAttribute('aria-expanded', 'false'); }
    }
    if (act === 'update') {
        var mbtn = menu ? document.querySelector('[data-aicli-card-menu][aria-controls="' + menu.id + '"]') : null;
        av2OpenUpdateOverlay(id, mbtn);
    } else if (act === 'release-notes') {
        var a = document.querySelector('.av2-card[data-agent="' + id + '"] .av2-changelog-link');
        if (a && /^https?:\/\//.test(a.getAttribute('href') || '')) window.open(a.getAttribute('href'), '_blank', 'noopener,noreferrer');
    }
});

// --- The foot: what each link frees (R14 to R16) ---------------------------------------------------------
// PURE: the foot as a list of parts, from the answer of agent_footprint. kind: remove | inuse | uninstall.
function av2FootSpec(r, measuring) {
    var out = [];
    function bracket(bytes) { return measuring ? ' (measuring…)' : (bytes == null ? '' : ' (frees ' + av2FmtBytes(bytes) + ')'); }
    if (r && r.old_in_use) out.push({ kind: 'inuse', text: 'Old versions in use' });
    else if (!r || !r.measured || Number(r.old_bytes) > 0) out.push({ kind: 'remove', text: 'Remove old versions', frees: bracket(r && r.measured ? Number(r.old_bytes) : null) });
    out.push({ kind: 'uninstall', text: 'Uninstall', frees: bracket(r && r.uninstall_bytes != null ? Number(r.uninstall_bytes) : null),
               bytes: (r && r.uninstall_bytes != null) ? Number(r.uninstall_bytes) : null });
    return out;
}

// Replace both brackets in place, with no page reload.
function av2RefreshFootSizes(id, done) {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=agent_footprint&agentId=' + encodeURIComponent(id) + '&csrf_token=' + csrf, function (r) {
        var holder = document.getElementById('av2-foot-links-' + id);
        if (!holder || !r || r.status !== 'ok') { if (done) done(null); return; }
        var card = document.getElementById('av2-card-' + id);
        var busy = !!(card && /^(queued|installing|done)$/.test(card.getAttribute('data-av2-state') || ''));
        var name = av2AgentName(id);
        var spec = av2FootSpec(r, !!r.measuring || busy);
        while (holder.firstChild) holder.removeChild(holder.firstChild);
        spec.forEach(function (p, i) {
            if (i > 0) holder.appendChild(av2h('span', 'av2-foot-sep', ' · ', { 'aria-hidden': 'true' }));
            if (p.kind === 'inuse') { holder.appendChild(av2h('span', 'av2-foot-note', p.text, { title: 'A workspace still runs these versions. Close it or switch it, then remove them.' })); return; }
            var b = av2h('button', 'av2-foot-link ' + (p.kind === 'remove' ? 'av2-remove-old' : 'av2-uninstall-link'), p.text, { type: 'button', 'data-agent': id });
            if (p.frees) b.appendChild(av2h('span', 'av2-frees' + (r.measuring || busy ? ' av2-muted' : ''), p.frees));
            if (busy) { b.disabled = true; b.setAttribute('aria-disabled', 'true'); }
            if (p.kind === 'remove') {
                b.setAttribute('data-testid', 'remove-old-' + id);
                b.title = 'Deletes the older versions of this agent that no workspace uses. The version you use stays. After this you cannot roll back to them.';
                b.onclick = function () { if (window.aicliRemoveOldVersions) window.aicliRemoveOldVersions(id); };
            } else {
                b.setAttribute('data-testid', 'uninstall-' + id);
                b.setAttribute('data-frees', p.bytes == null ? '' : String(p.bytes));
                b.setAttribute('aria-label', 'Uninstall ' + name);
                b.title = 'Removes this agent: the version you use, its old versions and its kept copies.';
                b.onclick = function () { uninstallAgent(id, b); };
            }
            holder.appendChild(b);
        });
        if (done) done(r);
    });
}

// While a measurement runs, ask again every 3 seconds (a file read each time) until it ends. With baseAt (the
// measured_at that the page had before a removal) it also waits until a newer measurement exists.
var _av2FootWatch = {};
function av2WatchFootSizes(id, n, baseAt) {
    n = n || 0;
    if (_av2FootWatch[id]) clearTimeout(_av2FootWatch[id]);
    _av2FootWatch[id] = setTimeout(function () {
        av2RefreshFootSizes(id, function (r) {
            if (!r || n >= 100) return;
            var waitNewer = baseAt !== undefined && !(Number(r.measured_at) > baseAt);
            if (r.measuring || waitNewer) av2WatchFootSizes(id, n + 1, baseAt);
        });
    }, 3000);
}
// After Remove old versions was queued: the job starts a new measurement when it ends. Watch for it.
function av2WatchFootAfterRemoval(agent) {
    var ids = (!agent || agent === 'all')
        ? Array.prototype.map.call(document.querySelectorAll('.av2-foot[data-agent]'), function (f) { return f.getAttribute('data-agent'); })
        : [agent];
    ids.forEach(function (id) {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=agent_footprint&agentId=' + encodeURIComponent(id) + '&csrf_token=' + csrf, function (r) {
            if (r && r.status === 'ok') av2WatchFootSizes(id, 0, Number(r.measured_at) || 0);
        });
    });
}
document.addEventListener('aicli:boot-space-measured', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.av2-foot[data-agent]'), function (f) { av2RefreshFootSizes(f.getAttribute('data-agent')); });
});

// Agents whose in-flight install is running beside live workspaces, and how
// many were open when it started — so the completion notice can tell the
// operator exactly what to do to pick the new version up.
var _sideBySideInstalls = {};

function doInstall(id, version, btn, sessionsToClose, backupOpts, forceNow, sideBySide) {
    // The click source can be a <button> OR a <select> (version picker). For
    // a select, mutating innerHTML would nuke the options, so we only swap
    // the waiting-state content on real buttons and just disable the select.
    var isSelect = btn && btn.tagName && btn.tagName.toLowerCase() === 'select';
    var originalContent = isSelect ? null : $(btn).html();
    var progress = $('#progress-' + id);
    var bar = $('#bar-' + id);
    var status = $('#status-text-' + id);
    var buttons = $('#buttons-' + id);
    _av2LastInstall[id] = { version: version, sessions: sessionsToClose, force: forceNow, sideBySide: sideBySide };

    $(btn).prop('disabled', true);
    if (!isSelect) $(btn).html('<i class="fa fa-spinner fa-spin"></i> WAIT...');

    // A side-by-side install is never queued and never closes anything: it is
    // simply an install that happens to run while workspaces are open.
    var safeQueue = !!(sessionsToClose && sessionsToClose > 0 && !forceNow && !sideBySide);
    var firstText;
    // Status text reflects what the server is actually doing in the first
    // ~1.8 s window: if sessions were listed in the confirm modal, the
    // backend is running _closeSessionsForUpgrade (Ctrl-C + sleep + sentinel);
    // otherwise it's starting install-bg directly. The real install-status
    // JSON takes over once polling kicks in.
    if (safeQueue) {
        firstText = 'Queueing safely — active sessions will keep running…';
    } else if (sideBySide) {
        firstText = 'Installing alongside your running version…';
    } else if (sessionsToClose && sessionsToClose > 0) {
        firstText = 'Closing ' + sessionsToClose + ' active session' + (sessionsToClose === 1 ? '' : 's') + '…';
    } else if (backupOpts && backupOpts.backup) {
        firstText = 'Backing up current version…';
    } else {
        firstText = 'Preparing installation…';
    }
    // One place sets the state of the card (AGENT_STORE_CARD_HEADER.md R1): the progress view takes the slot of the chips.
    av2SetCardState(id, safeQueue ? 'queued' : 'installing', firstText, { progress: safeQueue ? 1 : 5, version: version });
    // Nothing is announced to other tabs from here (EVENT_STREAM_MULTIPLEX.md R5).
    // A forced install writes its install marker on the server before it closes
    // any session; that marker's progress message reaches every device's
    // Terminal tab, which then reads list_active_installs and holds its terminals.

    var url = '/plugins/unraid-aicliagents/AICliAjax.php?action=install_agent&agentId=' + id + '&csrf_token=' + csrf;
    if (version) url += '&version=' + encodeURIComponent(version);
    if (forceNow) url += '&force=1';
    // WP #964 (slice): request a pre-upgrade backup of the current version.
    if (backupOpts && backupOpts.backup && backupOpts.dest) {
        url += '&backup=1&backup_dest=' + encodeURIComponent(backupOpts.dest);
    }

    $.getJSON(url, function(r) {
        if (r.status === 'error') {
            swal('Error', r.message, 'error');
            // Roll back the panel state + restore the click-source. A refused
            // install never wrote an install marker, so no other tab holds anything.
            av2SetCardState(id, 'idle', '');
            $(btn).prop('disabled', false);
            if (!isSelect) $(btn).html(originalContent);
            return;
        }
        if (r.status === 'queued') {
            bar.css('width', '1%');
            status.text(r.message || 'Upgrade queued safely — waiting for active sessions to close');
            _showCancelQueued(id);
        } else if (sideBySide || r.side_by_side) {
            // The Terminal tab decides what to hide from the server's own list:
            // list_active_installs marks this install `interrupts: false`, so it
            // hides nothing; an interrupting install is marked true and holds
            // that agent's terminals (#243, docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md).
            _sideBySideInstalls[id] = (r.open_sessions || sessionsToClose || 0);
        }
        startInstallPolling(id, progress, bar, status, buttons, btn, originalContent);
    }).fail(function(xhr) {
        swal('Error', 'Install request failed: ' + (xhr.statusText || 'network error'), 'error');
        av2SetCardState(id, 'idle', '');
        $(btn).prop('disabled', false);
        if (!isSelect) $(btn).html(originalContent);
    });
}

// Legacy wrapper (called from resume-on-load)
function installAgent(id, isUpdate, btn) {
    installVersionAgent(id, btn);
}

// EVENT_STREAM_MULTIPLEX.md R5: there is no cross-tab install broadcast any
// more. The Terminal tab learns install state from the server alone: the
// `install_<id>` / `activity` messages of its event stream prompt one read of
// list_active_installs, whose `interrupts` flag (#243) says whether the install
// may hide that agent's terminals. A side-by-side install never does
// (docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md) — that rule now lives on the
// server, where every device sees it, not in which tab happened to broadcast.

// #71 (cancel): let the user abandon a QUEUED upgrade (waiting for sessions to
// close) before it auto-fires. Poll handles are tracked per agent so Cancel can
// stop polling and revert the panel without a full page reload. Queued-only —
// the backend refuses once the binary swap has begun.
var _aicliInstallControls = {};
function _showCancelQueued(id) {
    if (document.getElementById('cancel-upg-' + id)) return;
    // The Cancel button sits in the fixed second line of the progress view, so the card keeps its height.
    var host = document.getElementById('extra-' + id) || (document.getElementById('status-text-' + id) || {}).parentNode;
    if (!host) return;
    var b = document.createElement('button');
    b.id = 'cancel-upg-' + id;
    b.type = 'button';
    b.className = 'aicli-btn-slim aicli-cancel-queued';
    b.textContent = 'Cancel';
    b.title = 'Cancel this queued upgrade — it will not start';
    b.style.cssText = 'cursor:pointer;';
    b.onclick = function() { cancelQueuedUpgrade(id); };
    host.insertBefore(b, host.firstChild);
}
function _hideCancelQueued(id) {
    var b = document.getElementById('cancel-upg-' + id);
    if (b && b.parentNode) b.parentNode.removeChild(b);
}
function cancelQueuedUpgrade(id) {
    var b = document.getElementById('cancel-upg-' + id);
    if (b) { b.disabled = true; b.textContent = 'Cancelling…'; }
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=cancel_agent_upgrade&agentId=' + id + '&csrf_token=' + csrf, function(r) {
        if (r && r.status === 'ok') {
            if (_aicliInstallControls[id]) { try { _aicliInstallControls[id].stop(); } catch (_e) {} delete _aicliInstallControls[id]; }
            _hideCancelQueued(id);
            av2SetCardState(id, 'idle', '', { progress: 0 });
            // Reconcile the card with the server now that both the pending
            // upgrade and its waiting activity have been cleared. Without this
            // reload a stale render can leave the primary button disabled as
            // WAIT... until the next full page refresh.
            setTimeout(function() { safeReload(); }, 0);
        } else {
            swal('Could not cancel', (r && r.message) || 'The upgrade may have already started.', 'warning');
            if (b) { b.disabled = false; b.textContent = 'Cancel'; }
        }
    }).fail(function() {
        if (b) { b.disabled = false; b.textContent = 'Cancel'; }
    });
}

function startInstallPolling(id, progress, bar, status, buttons, btn, originalContent) {
    // Nothing is announced to other tabs from here: the Terminal tab follows the
    // install from the server (EVENT_STREAM_MULTIPLEX.md R5), on every device.
    var isSelectBtn = btn && btn.tagName && btn.tagName.toLowerCase() === 'select';
    av2OpenCard(id);
    function handleProgress(d) {
        if (d.status === 'error') {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            delete _aicliInstallControls[id];
            _hideCancelQueued(id);
            swal("Failed", d.message, "error");
            // The failure stays on the card, in the slot of the chips, with Retry and Dismiss (the dialog may be missed).
            av2SetCardState(id, 'failed', (d.message || 'The install failed.'), { progress: 0 });
            if (btn) {
                $(btn).prop('disabled', false);
                if (!isSelectBtn) $(btn).html(originalContent);
            }
            return;
        }
        // #71: while still queued (waiting for sessions), keep the Cancel affordance;
        // remove it the moment the upgrade actually starts installing.
        if (d.reason === 'queued_for_active_sessions') { _showCancelQueued(id); }
        else { _hideCancelQueued(id); }
        if (typeof d.progress !== 'undefined' && d.progress >= 0) bar.css('width', d.progress + '%');
        if (d.status_text) status.text(d.status_text);
        else if (d.step) status.text(d.step);
        // #87: while an installed upgrade waits for running processes, the server
        // enumerates the blockers on every poll (pid, command, cwd, tmux, age, and
        // whether each is a plugin workspace). Show them under the status line so
        // the 99% wait is explained and refreshable, never an unexplained stall.
        // The list sits behind a Details toggle in the second line of the progress view, so a long list does not stretch the card.
        var extraEl = document.getElementById('extra-' + id);
        var blockersEl = extraEl ? extraEl.querySelector('.av2-install-blockers') : null;
        if (d.blockers && extraEl) {
            if (!blockersEl) {
                blockersEl = document.createElement('details');
                blockersEl.className = 'av2-install-blockers';
                blockersEl.appendChild(document.createElement('summary')).textContent = 'Details';
                blockersEl.appendChild(document.createElement('pre')).style.margin = '4px 0 0';
                extraEl.appendChild(blockersEl);
            }
            blockersEl.querySelector('pre').textContent = d.blockers;
        } else if (blockersEl) { blockersEl.parentNode.removeChild(blockersEl); }
        if (d.progress >= 100 || d.completed) {
            if (nchanSub) { nchanSub.stop(); nchanSub = null; }
            if (poller) clearInterval(poller);
            delete _aicliInstallControls[id];
            _hideCancelQueued(id);
            av2SetCardState(id, 'done', 'Installed', { progress: 100 });
            // The new layer changes both foot figures; a background measurement started when the install ended.
            av2WatchFootSizes(id);
            // A side-by-side install finishes with the operator's workspaces
            // still running the OLD version, and nothing on screen would say so.
            // That is the one thing they need to know, and the only moment it is
            // worth interrupting them for — otherwise they would reasonably
            // assume the workspace they are looking at is now on the new
            // version. Everything else about this install was deliberately
            // silent. docs/specs/UPGRADE_WITHOUT_INTERRUPTION.md
            var openAtStart = _sideBySideInstalls[id] || 0;
            delete _sideBySideInstalls[id];
            if (openAtStart > 0 && d.status !== 'error') {
                var name = (typeof agentRegistry === 'object' && agentRegistry[id] && agentRegistry[id].name) ? agentRegistry[id].name : id;
                swal({
                    title: 'Installed',
                    text: '<div style="text-align:left;line-height:1.6">'
                        + name + ' is installed and ready.<br><br>'
                        + 'Your ' + openAtStart + ' open workspace' + (openAtStart === 1 ? '' : 's')
                        + ' ' + (openAtStart === 1 ? 'is' : 'are') + ' still running the version '
                        + (openAtStart === 1 ? 'it' : 'they') + ' started with, and '
                        + (openAtStart === 1 ? 'was' : 'were') + ' never interrupted.<br><br>'
                        + '<b>To move one onto this version, open its &ldquo;&hellip;&rdquo; menu and choose Switch to&nbsp;&hellip;</b>'
                        + '</div>',
                    html: true,
                    type: 'success',
                    confirmButtonText: 'Got it',
                }, function() { safeReload(); });
            } else {
                setTimeout(function() { safeReload(); }, 1500);
            }
        }
    }

    var nchanSub = null;
    var poller = null;
    if (typeof window.aicli_subscribeInstall === 'function') {
        nchanSub = window.aicli_subscribeInstall(id, handleProgress);
    }
    // EVENT_FIRST_RECONCILIATION.md fact table: "Install / upgrade progress …
    // 5s only while a progress bar is shown" — this poller's whole lifetime IS
    // "a progress bar is shown" (created here, torn down at completion below),
    // so 5s applies whether or not the nchan push is also connected; there is
    // no separate "idle" state for it to be in.
    poller = setInterval(function() {
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, handleProgress);
    }, 5000);
    // #71: expose stop() so Cancel can halt polling without a page reload.
    _aicliInstallControls[id] = { stop: function() {
        if (nchanSub) { nchanSub.stop(); nchanSub = null; }
        if (poller) { clearInterval(poller); poller = null; }
    } };
}

// --- Version Picker Population ---

var _versionPollTimer = null;
var _versionCacheRequestSeq = 0;

// The picker data is the durable server truth. Reconcile the visible radio
// after every cache response so a failed or superseded channel write cannot
// leave the Store card claiming a channel the server did not save.
function syncChannelControl(agentId, channel) {
    channel = ['stable', 'beta', 'pinned'].indexOf(channel) >= 0 ? channel : 'stable';
    var control = document.querySelector('input[name="ch-' + agentId + '"][value="' + channel + '"]');
    if (control) control.checked = true;
}

// Forgejo #447: say what the version figures ARE. On load the cards show the last
// CACHED "latest version"; nothing said so, nothing showed that an online check
// was running (the page starts one in the background when the cache is stale),
// and a click during that check returned "already in progress" as a plain OK, so
// the button looked dead. One status line next to the button now carries the
// truth, and the cards refresh the moment the check ends (the tray entry
// `version_check` arrives on the page's event stream).
function _vcAge(ts) {
    var s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (s < 90) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    return Math.round(s / 86400) + ' d ago';
}
// The step of the running check, from the tray entry on the event stream. It
// stays until the check ends, so a render without a step does not erase it.
var _vcLive = null;
function _vcCheckingHtml(live) {
    if (!live || !live.step) {
        return '<i class="fa fa-spinner fa-spin"></i> Checking online for new versions…';
    }
    var pct = Math.max(0, Math.min(100, live.progress || 0));
    return '<span class="vc-live" style="display:inline-flex; flex-direction:column; gap:3px; max-width:220px; vertical-align:middle;">'
        + '<span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fa fa-spinner fa-spin"></i> Checking ' + _escAttr(live.step) + '</span>'
        + '<span style="display:block; height:3px; border-radius:2px; background:rgba(128,128,128,0.25); overflow:hidden;">'
        + '<span class="vc-live-bar" style="display:block; height:100%; width:' + pct + '%; background:var(--orange, #e68a00); transition:width .3s;"></span>'
        + '</span></span>';
}
function renderVersionCheckStatus(r, note) {
    var el = document.getElementById('version-check-status');
    if (!el) return;
    var checking = !!(r && r.checking);
    if (!checking) _vcLive = null;
    var checkedAt = r && r.checked_at ? parseInt(r.checked_at, 10) : 0;
    var errors = r && r.errors ? parseInt(r.errors, 10) : 0;
    var firstError = (r && r.first_error) ? String(r.first_error) : '';
    el.style.color = '';
    if (checking) {
        // The same step and bar as the Activity tray row: "Kimi Code (15 of 15)".
        if (r.step) _vcLive = { step: String(r.step), progress: parseInt(r.progress, 10) || 0 };
        el.innerHTML = _vcCheckingHtml(_vcLive);
    } else if (errors > 0) {
        el.style.color = 'var(--orange, #e68a00)';
        el.textContent = 'Check failed for ' + errors + ' agent' + (errors === 1 ? '' : 's') + (firstError ? ': ' + firstError : '') + (checkedAt ? ' \u00b7 last good check ' + _vcAge(checkedAt) : '');
    } else if (checkedAt) {
        var age = _vcAge(checkedAt);
        el.textContent = (note || (age === 'just now' ? 'Checked just now' : 'Checked ' + age));
        if (Date.now() / 1000 - checkedAt > 3600) el.style.color = 'var(--orange, #e68a00)';
    } else {
        el.textContent = note || 'Versions not checked yet';
    }
    var btn = document.getElementById('version-check-btn');
    if (btn) { btn.disabled = checking; btn.title = checking ? 'A check is running' : 'Look online for new versions now'; }
}
var _vcLiveHooked = false;
function _vcHookLiveUpdates() {
    if (_vcLiveHooked) return;
    _vcLiveHooked = true;
    try {
        var bus = window.aicliEvents;
        if (bus && typeof bus.on === 'function') {
            bus.on('activity', function (evt) {
                var d = evt && evt.data;
                if (!d || d.opId !== 'version_check' || !d.status) return;
                // While the check runs, show its step beside the button (#826);
                // when it ends, refresh the cards.
                if (d.status === 'running') renderVersionCheckStatus({ checking: true, step: d.step, progress: d.progress });
                else loadVersionCache();
            });
        }
    } catch (e) { /* the 30 s poll below still catches up */ }
}
function loadVersionCache() {
    _vcHookLiveUpdates();
    var requestSeq = ++_versionCacheRequestSeq;
    if (_versionPollTimer) {
        clearInterval(_versionPollTimer);
        _versionPollTimer = null;
    }
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r) {
        // A channel change starts a newer request. Do not let the older
        // stable response arrive later and repaint the Beta picker.
        if (requestSeq !== _versionCacheRequestSeq) return;
        if (r.status !== 'ok' || !r.dropdowns) return;
        renderVersionCheckStatus(r);

        var allHaveData = true;
        $.each(r.dropdowns, function(id, data) {
            syncChannelControl(id, data.channel);
            _av2Dropdowns[id] = data;
            populateVersionPicker(id, data);
            updateAgentBadge(id, data);
            // Check if this agent's select exists (installed) but has no version data
            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                allHaveData = false;
            }
        });

        // If any installed agent is missing version data, or a check is running
        // (#447), poll until the background check fills it in
        // (getVersionCache triggers a background check when it detects stale entries)
        if (!allHaveData || r.checking) {
            if (!_versionPollTimer) {
                var _pollCount = 0;
                _versionPollTimer = setInterval(function() {
                    if (requestSeq !== _versionCacheRequestSeq) {
                        clearInterval(_versionPollTimer);
                        _versionPollTimer = null;
                        return;
                    }
                    _pollCount++;
                    if (_pollCount > 20) { clearInterval(_versionPollTimer); _versionPollTimer = null; return; } // Max 60s
                    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_version_cache&csrf_token=' + csrf, function(r2) {
                        if (requestSeq !== _versionCacheRequestSeq) return;
                        if (r2.status !== 'ok' || !r2.dropdowns) return;
                        renderVersionCheckStatus(r2);
                        var nowAllHaveData = !r2.checking;
                        $.each(r2.dropdowns, function(id, data) {
                            syncChannelControl(id, data.channel);
                            _av2Dropdowns[id] = data;
                            populateVersionPicker(id, data);
                            updateAgentBadge(id, data);
                            if (document.getElementById('version-select-' + id) && (!data.versions || data.versions.length === 0)) {
                                nowAllHaveData = false;
                            }
                        });
                        if (nowAllHaveData) {
                            clearInterval(_versionPollTimer);
                            _versionPollTimer = null;
                        }
                    });
                }, 3000);
            }
        }
    });
}

function populateVersionPicker(id, data) {
    var select = document.getElementById('version-select-' + id);
    if (!select) return;

    var installed = data.installed || '0.0.0';
    var channel = data.channel || 'stable';
    select.setAttribute('data-installed', installed);
    // Safe clear — avoids innerHTML which the security hook will block.
    while (select.firstChild) select.removeChild(select.firstChild);

    // Empty state (1): server returned no version data yet (cache miss or check
    // error). Keep polling — the background VersionCheckService will fill this in.
    if (!data.versions || data.versions.length === 0) {
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.disabled = true;
        emptyOpt.selected = true;
        var channelLabel = (data.channel === 'beta') ? 'beta' : 'stable';
        emptyOpt.textContent = 'No versions available on this channel (' + channelLabel + ')';
        select.appendChild(emptyOpt);
        // WP #964: kept backups stay restorable even with no upstream versions.
        select.disabled = (appendRetainedBackups(select) === 0);
        return;
    }
    // Versions are available — ensure the select is usable.
    select.disabled = false;

    // Platform filter: Unraid runs x86_64 Linux, so any version tagged with
    // a non-Linux OS (win32, darwin, freebsd) or non-x64 arch (arm64, aarch64,
    // arm32, armv7, ppc64, s390x, riscv) can't execute here. Observed on agents
    // like Pi Coder whose npm dist-tags expose per-platform binaries.
    // Untagged versions (generic releases) are always kept.
    var compatibleVersions = data.versions.filter(function(v) {
        var tags = v.tags || [];
        if (tags.length === 0) return true;
        return tags.every(function(t) {
            t = String(t).toLowerCase();
            if (/(^|[-_.])(win32|windows|darwin|mac|macos|freebsd|netbsd|openbsd|sunos)($|[-_.])/.test(t)) return false;
            if (/(^|[-_.])(arm64|aarch64|arm32|armv7l?|armv6l?|armhf|ppc64|ppc64le|s390x|riscv|mips)($|[-_.])/.test(t)) return false;
            return true;
        });
    });

    // Pre-release filter: on the Stable channel hide versions whose dist-tags
    // are exclusively pre-release markers. Codex-CLI exposes alpha-linux-x64,
    // alpha, etc. in its npm dist-tags — they should not appear in a stable picker.
    // A version with no tags (untagged) or with at least one non-pre-release tag
    // (e.g. 'latest') is always kept.
    var preReleaseRe = /alpha|beta|canary|nightly|snapshot|next(?:-\d|$)|rc(?:\d|$)|dev[-_]build|pre[-_]?release/i;
    if (channel === 'stable' || channel === 'latest') {
        compatibleVersions = compatibleVersions.filter(function(v) {
            var tags = v.tags || [];
            if (tags.length === 0) return true;
            return !tags.every(function(t) { return preReleaseRe.test(String(t)); });
        });
    }

    // Empty state (2): server returned versions but all were filtered out by
    // platform or pre-release rules for this channel (e.g. pi-coder on beta:
    // the upstream has versions but none survive the stable-channel pre-release
    // filter). Show a disabled placeholder so the user isn't left with a blank
    // picker — no polling needed since the data arrived and filtering is definitive.
    if (compatibleVersions.length === 0) {
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.disabled = true;
        emptyOpt.selected = true;
        var channelLabel = (data.channel === 'beta') ? 'beta' : 'stable';
        emptyOpt.textContent = 'No versions available on this channel (' + channelLabel + ')';
        select.appendChild(emptyOpt);
        // WP #964: kept backups stay restorable even with no upstream versions.
        select.disabled = (appendRetainedBackups(select) === 0);
        return;
    }

    compatibleVersions.forEach(function(v) {
        var opt = document.createElement('option');
        opt.value = v.version;
        // Always prefix with 'v' for consistency with the rest of the card
        // (title badge, stats dl, etc. all render v1.2.3).
        var label = 'v' + v.version;
        // De-dup: non-NPM agents emit tags=['installed'] as their source-of-truth,
        // but we also auto-append "(installed)" for the installed-version row.
        var tags = (v.tags || []).filter(function(t) { return t !== 'installed'; });
        if (tags.length > 0) {
            label += ' ' + tags.map(function(t) { return '[' + t + ']'; }).join(' ');
        }
        if (v.version === installed) label += ' (installed)';
        opt.textContent = label;
        if (v.version === installed) opt.selected = true;
        select.appendChild(opt);
    });

    // If installed version isn't in the list, add it at the top —
    // BUT skip the "0.0.0" sentinel that AgentRegistry returns for never-installed
    // agents. Otherwise the picker auto-selects 0.0.0 and Install passes that as
    // the NPM target, which always 404s (Pi Coder install-fail root cause).
    if (installed && installed !== '0.0.0' && installed !== 'unknown' &&
        !Array.from(select.options).some(function(o) { return o.value === installed; })) {
        var opt = document.createElement('option');
        opt.value = installed;
        opt.textContent = 'v' + installed + ' (installed)';
        opt.selected = true;
        select.insertBefore(opt, select.firstChild);
    }

    // WP #964: append the "Restore a kept backup" optgroup last, so retained
    // local backups always sit below the live upstream versions.
    appendRetainedBackups(select);
}

// WP #964: format the YYYYMMDDTHHMMSSZ backup stamp as a readable UTC date.
function _fmtBackupStamp(stamp) {
    var s = String(stamp || '');
    var m = s.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/);
    return m ? (m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ' UTC') : s;
}

// WP #964: append an <optgroup> of locally-retained version backups to a
// version picker. The backup list is rendered server-side onto the <select>
// as a data-backups JSON attribute (it survives the populateVersionPicker
// rebuild because only child <option>s are cleared, not attributes).
// Returns the number of backup options appended.
function appendRetainedBackups(select) {
    if (!select) return 0;
    var raw = select.getAttribute('data-backups');
    if (!raw) return 0;
    var backups;
    try { backups = JSON.parse(raw); } catch (e) { return 0; }
    if (!backups || !backups.length) return 0;

    // Don't offer a "restore" to the version that's already installed — it is
    // already the top entry of the picker, and rolling back to it is a no-op.
    var installed = select.getAttribute('data-installed');
    var restorable = backups.filter(function(b) { return b.version !== installed; });
    if (!restorable.length) return 0;

    var group = document.createElement('optgroup');
    group.label = 'Restore a kept backup';
    restorable.forEach(function(b) {
        var opt = document.createElement('option');
        // Sentinel value — never forwarded as an install target. onVersionSelect
        // routes it to the restore flow via the data-restore marker below.
        opt.value = 'restore:' + b.dir;
        opt.setAttribute('data-restore', '1');
        opt.setAttribute('data-backup-dir', b.dir);
        opt.setAttribute('data-restore-version', b.version);
        var mb = b.bytes ? (Math.round(b.bytes / 1048576 * 10) / 10) + ' MB' : '';
        opt.textContent = 'v' + b.version + ' — kept ' + _fmtBackupStamp(b.created_at)
                          + (mb ? ' (' + mb + ')' : '');
        group.appendChild(opt);
    });
    select.appendChild(group);
    return restorable.length;
}

function updateAgentBadge(id, data) {
    var item = $('[data-id="' + id + '"]');
    if (!item.length) return;

    var actionBtn = item.find('.agent-action-btn');
    if (!actionBtn.length) return;

    if (data.update) {
        item.addClass('has-update').removeClass('has-downgrade');
        // Update the action button
        actionBtn.removeClass('danger').addClass('info')
            .html('<i class="fa fa-arrow-circle-up"></i> UPGRADE to v' + $('<div>').text(String(data.update.available)).html());
        // Pre-select the upgrade version in the dropdown so the install uses it
        var select = document.getElementById('version-select-' + id);
        if (select) { select.value = data.update.available; }
        // Update badge in header
        var meta = item.find('.agent-meta');
        if (!meta.find('.update-avail').length) {
            meta.append('<span class="agent-status-badge update-avail"><i class="fa fa-arrow-circle-up"></i> v' + $('<div>').text(String(data.update.available)).html() + '</span>');
        }
    }
}

// Version picker change handler. Fires the install directly with the chosen
// version (which installVersionAgent turns into install, upgrade, downgrade,
// or reinstall depending on the comparison to the currently-installed
// version). Works uniformly for every source type — NpmSource, GithubRelease
// Source, CurlInstallSource, and TarballSource all accept an explicit
// $targetVersion through the AgentSource::fetch interface.
function onVersionSelect(select) {
    var id = select.getAttribute('data-agent');
    var version = select.value;
    var installed = select.getAttribute('data-installed');
    if (!id) return;

    // WP #964: a "kept backup" option routes to a restore, not an install.
    var picked = select.options[select.selectedIndex];
    if (picked && picked.getAttribute('data-restore') === '1') {
        restoreAgentBackup(id,
            picked.getAttribute('data-backup-dir'),
            picked.getAttribute('data-restore-version'),
            select);
        return;
    }

    if (!version) return;
    // Strip the sentinel from entry labels; never forward empty/sentinel targets
    if (version === '0.0.0' || version === 'unknown' || version === 'installed') return;
    // Re-picking the current version is a no-op (prevents spurious reinstalls
    // when the <select> is rebuilt after a refresh and fires a synthetic change).
    if (version === installed) return;

    // Choosing one explicit version is an install decision, not a release
    // channel decision. The radio is the only control allowed to persist
    // Stable / Beta / Pinned. In particular, never infer a channel from a
    // cached response or a version's upstream tag: an older cache can describe
    // Stable while the operator has deliberately selected Beta, and writing
    // that inference back would silently undo their preference.

    // Use the picker itself as the click-source for button-state UI. Works
    // on both v2 cards (.av2-card) and the legacy card layout.
    installVersionAgent(id, select, version);
}

// WP #964: restore an agent to a locally-retained version backup. Routed here
// from onVersionSelect when a "Restore a kept backup" optgroup option is
// picked. The backend (restore_agent_backup) snapshots the current version
// first under the per-entity storage lock, so the action is itself reversible.
function restoreAgentBackup(id, backupDir, version, select) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    // Reset the picker to the installed version — the swal dialog now carries
    // the restore intent, and a confirmed restore reloads the page anyway.
    if (select) select.value = select.getAttribute('data-installed') || '';
    if (!backupDir || !version) {
        swal('Restore failed', 'Backup details missing — refresh the page and retry.', 'error');
        return;
    }
    swal({
        title: 'Restore ' + id + ' to v' + version + '?',
        text: 'Restore ' + id + ' from your locally-kept backup of v' + version
            + '. This replaces the currently-installed version. A backup of the '
            + 'current version is taken first, so the restore is reversible.',
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Restore v' + version,
        confirmButtonColor: '#e67e22',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function(confirmed) {
        if (!confirmed) return;
        $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php',
            type: 'GET',
            data: { action: 'restore_agent_backup', agentId: id, backup_dir: backupDir, csrf_token: token },
            dataType: 'json'
        }).done(function(r) {
            if (r && r.status === 'ok') {
                swal({ title: 'Restored', text: id + ' is now on v' + version + '.', type: 'success', timer: 1800, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1200);
            } else {
                swal('Restore failed', (r && r.message) ? r.message : 'Check the lifecycle log.', 'error');
            }
        }).fail(function() {
            swal('Restore failed', 'AJAX request failed. Check debug.log.', 'error');
        });
    });
}

// Simple semver compare (returns -1, 0, 1)
function versionCompare(a, b) {
    var pa = a.replace(/[^0-9.]/g, '').split('.').map(Number);
    var pb = b.replace(/[^0-9.]/g, '').split('.').map(Number);
    for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
        var na = pa[i] || 0, nb = pb[i] || 0;
        if (na > nb) return 1;
        if (na < nb) return -1;
    }
    return 0;
}

// --- Page Load ---

$(function() {
    // Resume in-progress installations. v2 cards use .av2-install-panel.active.
    $('.av2-install-panel.active').each(function() {
        var id = this.id.replace('progress-', '');
        if (!id) return;
        var bar = $('#bar-' + id);
        var status = $('#status-text-' + id);
        var progress = $(this);
        var buttons = $('#buttons-' + id);
        _hideButtons(buttons);

        // Quick check: if install already completed, just reload immediately
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_install_status&agentId=' + id + '&csrf_token=' + csrf, function(d) {
            if (d.progress >= 100 || d.completed || d.progress < 0) {
                // Already done or no status — hide bar and reload
                progress.removeClass('active'); _showButtons(buttons);
                safeReload();
            } else {
                // Still in progress — start polling
                startInstallPolling(id, progress, bar, status, buttons, null, '');
            }
        }).fail(function() {
            // Status check failed — start polling anyway
            startInstallPolling(id, progress, bar, status, buttons, null, '');
        });
    });

    // A card whose foot says "(measuring…)" asks again until the background measurement ends (R16).
    $('.av2-foot[data-agent]').each(function() {
        if ($(this).find('.av2-frees.av2-muted').length) av2WatchFootSizes(this.getAttribute('data-agent'));
    });

    // Load version cache and populate pickers
    loadVersionCache();
});

// --- Existing Functions ---

function uninstallAgent(id, btn) {
    // The confirm says what is freed: the same number as the foot link (it is the list that Uninstall deletes).
    var name = av2AgentName(id);
    var frees = btn && btn.getAttribute ? btn.getAttribute('data-frees') : '';
    var text = 'This removes the agent, all its versions and its kept copies'
        + (frees !== null && frees !== '' ? ', and frees ' + av2FmtBytes(Number(frees)) + ' on the disk' : '')
        + '. Its workspaces close and leave the list. Your variables and secrets for it stay.';
    swal({ title: "Uninstall " + name + "?", text: text, type: "warning", showCancelButton: true, confirmButtonText: "Uninstall", cancelButtonText: "Cancel", showLoaderOnConfirm: true, closeOnConfirm: false }, function(confirmed) {
        if (!confirmed) return;
        $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=uninstall_agent&agentId=' + id + '&csrf_token=' + csrf, function(r) {
            if (r && r.status === 'ok') {
                swal({ title: "Uninstalled", text: name + " has been removed.", type: "success", timer: 1500, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1600);
            } else {
                swal("Uninstall Failed", (r && r.message) || "Unknown error. Check debug.log", "error");
            }
        }).fail(function() {
            swal("Uninstall Failed", "Server communication error. Check debug.log", "error");
        });
    });
}
function setAgentFilter(filter, el) {
    agentFilter = filter;
    localStorage.setItem('aicli_agent_filter', filter);
    $('.filter-btn').removeClass('active').attr('aria-pressed', 'false');
    $(el).addClass('active').attr('aria-pressed', 'true');
    filterAgents();
}

// Chrome's autofill fires asynchronously after the page's initial JS runs,
// so autocomplete="off" alone doesn't block it — the username "root" keeps
// appearing in the agent-search input. Defence in depth:
//   1) On DOMContentLoaded + a 200ms delay, wipe any value Chrome may have filled.
//   2) Register a one-shot "change" listener that wipes again if autofill slips through.
//   3) Re-run filterAgents after the wipe so the card grid stays in sync.
$(function() {
    function killAutofill() {
        var el = document.getElementById('agent-search-input');
        if (!el) return;
        if (el.value && el.value !== el.dataset.userTyped) {
            el.value = '';
            if (typeof filterAgents === 'function') filterAgents();
        }
    }
    setTimeout(killAutofill, 200);
    setTimeout(killAutofill, 800);
    $('#agent-search-input').on('input', function() {
        // Record user-typed values so the autofill killer doesn't wipe them.
        this.dataset.userTyped = this.value;
    });

    // AGENT_STORE_FILTER (2026-09-29): with no agent installed (a first install)
    // the store opens on All. The saved filter lives in this browser, so it can
    // come from an earlier install or another server at the same address, and
    // "Installed" or "Updates" would then show an empty store.
    if (agentFilter !== 'all' && $('.av2-card[data-installed="1"]').length === 0) {
        agentFilter = 'all';
        try { localStorage.setItem('aicli_agent_filter', 'all'); } catch (e) { /* private mode */ }
    }
    // Restore filter button visual state (agentFilter itself is already seeded
    // from localStorage in ManagerGlobalState.php on page load).
    if (agentFilter !== 'all') {
        $('.filter-btn').removeClass('active').attr('aria-pressed', 'false');
        $('.filter-btn').each(function() {
            var m = ($(this).attr('onclick') || '').match(/setAgentFilter\('([^']+)'/);
            if (m && m[1] === agentFilter) { $(this).addClass('active').attr('aria-pressed', 'true'); return false; }
        });
    }
    // Restore search input — set userTyped so killAutofill won't clear it.
    var savedSearch = localStorage.getItem('aicli_agent_search') || '';
    if (savedSearch) {
        var si = document.getElementById('agent-search-input');
        if (si) { si.value = savedSearch; si.dataset.userTyped = savedSearch; }
    }
    if (agentFilter !== 'all' || savedSearch) filterAgents();

    // Apply the persisted A-Z / Z-A card order (defaults to A-Z on first load).
    initAgentSort();
});

// --- WP #964 follow-on: agent card A-Z / Z-A ordering toggle ---------------
// Reorders the .av2-card nodes inside #agent-store-grid by their data-name.
// The chosen direction persists in localStorage; default is A-Z. Independent
// of filterAgents() — sorting reorders, filtering toggles display.
var AGENT_SORT_KEY = 'aicli_agent_sort';

function applyAgentSort(dir) {
    dir = (dir === 'desc') ? 'desc' : 'asc';
    var grid = document.getElementById('agent-store-grid');
    if (grid) {
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.av2-card'));
        cards.sort(function(a, b) {
            var an = (a.getAttribute('data-name') || '').toLowerCase();
            var bn = (b.getAttribute('data-name') || '').toLowerCase();
            if (an < bn) return dir === 'desc' ? 1 : -1;
            if (an > bn) return dir === 'desc' ? -1 : 1;
            return 0;
        });
        // Phase 7: the order is the saved card order of the Store tab (settings-cards.js), so a
        // sort and a move use one list. Without that script the cards are moved here.
        if (window.aicliCards && typeof window.aicliCards.setOrder === 'function') {
            window.aicliCards.setOrder(grid, cards.map(function(c) { return c.getAttribute('data-aicli-card'); }));
        } else {
            cards.forEach(function(c) { grid.appendChild(c); });
        }
    }
    _paintAgentSort(dir);
}

function _paintAgentSort(dir) {
    var icon  = document.getElementById('agent-sort-icon');
    var label = document.getElementById('agent-sort-label');
    var btn   = document.getElementById('agent-sort-toggle');
    if (icon)  icon.className = 'fa fa-sort-alpha-' + (dir === 'desc' ? 'desc' : 'asc');
    if (label) label.textContent = (dir === 'desc' ? 'Z–A' : 'A–Z');
    if (btn)   btn.setAttribute('data-dir', dir);
}

function toggleAgentSort() {
    var btn  = document.getElementById('agent-sort-toggle');
    var cur  = btn ? btn.getAttribute('data-dir') : 'asc';
    var next = (cur === 'desc') ? 'asc' : 'desc';
    try { localStorage.setItem(AGENT_SORT_KEY, next); } catch (e) {}
    applyAgentSort(next);
}

// A saved card order (a move or a sort of the person) wins over the A-Z order that the server prints.
function _storeHasSavedOrder() {
    try {
        var key = (window.aicliCards && window.aicliCards.KEY) || 'aicli_settings_layout_v1';
        var all = JSON.parse(localStorage.getItem(key) || 'null');
        return !!(all && all.store && Array.isArray(all.store.order) && all.store.order.length);
    } catch (e) { return false; }
}

function initAgentSort() {
    var dir = 'asc';
    try { dir = localStorage.getItem(AGENT_SORT_KEY) || 'asc'; } catch (e) {}
    if (_storeHasSavedOrder() || dir !== 'desc') { _paintAgentSort(dir === 'desc' ? 'desc' : 'asc'); return; }
    applyAgentSort(dir);
}

// "Reset layout" (settings-cards.js) shows the cards A-Z again. The sort button follows.
function av2AfterLayoutReset() {
    setTimeout(function() {
        try { localStorage.removeItem(AGENT_SORT_KEY); } catch (e) {}
        _paintAgentSort('asc');
    }, 0);
}

// A card that installs shows its progress: open the card while it installs (not saved).
function av2OpenCard(id) {
    if (!window.aicliCards || typeof window.aicliCards.setWarning !== 'function') return;
    var cardId = ('agent-' + String(id).toLowerCase()).replace(/[^a-z0-9-]/g, '');
    // #793: the version line already says "Installing v…"; open the card, keep that line as it is.
    window.aicliCards.setWarning(cardId, true, 'Installing', { keepStatus: true });
}

function filterAgents() {
    const search = ($('#agent-search-input').val() || '').trim().toLowerCase();
    localStorage.setItem('aicli_agent_search', search);
    const cards = $('.av2-card');
    // v2 selector: .av2-card. installed/update state lives on data-* attrs.
    // Count match decisions inline — `cards.filter(':visible').length` polls
    // the DOM's computed visibility which can return 0 during initial render
    // (before CSS grid layout assigns offsetWidth/Height to the cards), so
    // the empty-state banner could appear even when every card matches the
    // filter. Counting from our own show/hide flag is layout-agnostic.
    let visible = 0;
    cards.each(function() {
        const item = $(this);
        const name = (item.data('name') || '').toLowerCase();
        const isInstalled = item.attr('data-installed') === '1';
        const hasUpdate = item.attr('data-has-update') === '1';
        let show = !search || name.includes(search);
        if (show) {
            if (agentFilter === 'installed' && !isInstalled) show = false;
            if (agentFilter === 'updates' && !hasUpdate) show = false;
        }
        if (show) { item.show(); visible++; } else { item.hide(); }
    });
    // Safety net: if the filter matched zero visible cards but there are cards
    // in the DOM (e.g. Chrome autofill put junk in the search input), surface a
    // one-off clear button rather than leaving the user staring at an empty grid.
    var $banner = $('#agent-search-empty-banner');
    if (visible === 0 && cards.length > 0 && (search || agentFilter !== 'all')) {
        if (!$banner.length) {
            $banner = $('<div id="agent-search-empty-banner" style="padding: 16px; text-align: center; color: var(--text-color); opacity: 0.65; grid-column: 1/-1; border: 1px dashed var(--border-color); border-radius: 8px;">No agents match <strong id="agent-search-empty-q"></strong>. <button type="button" class="av2-btn ghost" onclick="document.getElementById(\'agent-search-input\').value=\'\'; setAgentFilter(\'all\', document.querySelector(\'.filter-btn\')); filterAgents();" style="margin-left: 10px;">Clear search</button></div>');
            $('#agent-store-grid').append($banner);
        }
        $('#agent-search-empty-q').text(search ? '"' + search + '"' : 'the current filter');
        $banner.show();
    } else if ($banner.length) {
        $banner.hide();
    }
}

function checkUpdates(btn) {
    if (btn) {
        var $b = $(btn);
        $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
    }
    renderVersionCheckStatus({ checking: true });
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=check_versions&csrf_token=' + csrf, function(r) {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for updates');
        if (r.status === 'ok' && r.running) {
            // #447: a check started by the page (or another tab) holds the lock.
            // Say so and follow it to the end instead of pretending nothing happened.
            renderVersionCheckStatus({ checking: true });
            loadVersionCache();
            return;
        }
        if (r.status === 'ok') {
            renderVersionCheckStatus(r, 'Checked just now');
            loadVersionCache(); // Refresh dropdowns with new data
        } else {
            renderVersionCheckStatus({ errors: 1, first_error: r.message || 'unknown error' });
        }
    }).fail(function(xhr) {
        if (btn) $(btn).prop('disabled', false).html('<i class="fa fa-refresh"></i> Check for updates');
        renderVersionCheckStatus({ errors: 1, first_error: 'the request failed (' + (xhr && xhr.status ? xhr.status : 'network') + ')' });
        swal("Error", "Failed to check for updates. Check debug.log", "error");
    });
}

function toggleAgentConfig(id, el) {
    const panel = $('#config-panel-' + id);
    if (panel.hasClass('collapsed')) panel.removeClass('collapsed').hide().slideDown(200);
    else panel.slideUp(200, function() { panel.addClass('collapsed'); });
}

// ==========================================================================
// Agent Card v2 — chip accordion, diff-detect rows, panel lazy-load + save.
// All dynamic HTML values flow through av2esc() for HTML-entity escaping
// before being written into the DOM.
// ==========================================================================

function av2ChipToggle(chip) {
    const card = chip.closest('.av2-card');
    if (!card) return;
    const target = chip.getAttribute('data-target');
    const already = chip.getAttribute('aria-expanded') === 'true';

    card.querySelectorAll('.av2-chip').forEach(c => c.setAttribute('aria-expanded', 'false'));
    card.querySelectorAll('.av2-panel').forEach(p => p.classList.remove('open'));

    if (already) return;
    chip.setAttribute('aria-expanded', 'true');
    const panel = card.querySelector('.av2-panel[data-panel="' + target + '"]');
    if (!panel) return;
    panel.classList.add('open');

    const agentId = card.getAttribute('data-agent');
    if (target === 'terminal' && !panel.dataset.loaded) { av2LoadTmuxPanel(agentId, panel); panel.dataset.loaded = '1'; }
    // Storage lives inside the Resources (runtime) panel now — lazy-load its body
    // on first open. Re-opening the chip reuses the cached render.
    // Note arg order: av2LoadStoragePanel(panelOrBody, agentId) — passing them
     // the other way round silently throws (strings have no classList) and the
     // "Loading…" placeholder stays forever.
     if (target === 'runtime' && !panel.dataset.storageLoaded) { av2LoadStoragePanel(panel, agentId); panel.dataset.storageLoaded = '1'; }
}

function av2DiffCheck(el) {
    const row = el.closest('.av2-row');
    if (!row) return;
    const def = row.dataset.agentDefault ?? row.dataset.builtin ?? '';
    row.classList.toggle('modified', String(el.value) !== String(def));
}
function av2ResetRow(btn, def) {
    const row = btn.closest('.av2-row');
    if (!row) return;
    const field = row.querySelector('input, select');
    if (!field) return;
    field.value = def;
    row.classList.remove('modified');
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function av2SaveAgentSetting(el) {
    const form = document.getElementById('aicli-settings-form');
    if (form && typeof saveAICliAgentsManager === 'function') saveAICliAgentsManager(form, true);
}

// Persist the selected release channel for an agent. Fires from the
// Stable / Beta / Pinned radio buttons. Server-side: set_agent_channel.
// On success, loadVersionCache() re-fetches get_version_cache, which calls
// VersionCheckService::getAvailableVersions — filtered server-side by the
// newly-saved channel — and repopulates the version dropdown via
// populateVersionPicker(). This is the required channel-change dropdown
// refresh (WP #264 Task 4): no separate re-fetch step is needed.
function av2SetChannel(agentId, channel) {
    var request = $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=set_agent_channel&agentId=' + encodeURIComponent(agentId) + '&channel=' + encodeURIComponent(channel) + '&csrf_token=' + csrf);
    request.done(function(r) {
        if (r && r.status === 'ok') {
            // Re-fetch version cache so dropdown options reflect the new channel's
            // available versions (server-side filter in getAvailableVersions).
            if (typeof loadVersionCache === 'function') loadVersionCache();
            return;
        }
        // The endpoint returns its last durable value on a write/readback error.
        // Apply it immediately, then reconcile every card from the cache response.
        if (r && r.channel) syncChannelControl(agentId, r.channel);
        if (typeof loadVersionCache === 'function') loadVersionCache();
        if (r && r.message) swal('Error', r.message, 'error');
    }).fail(function() {
        if (typeof loadVersionCache === 'function') loadVersionCache();
        swal('Error', 'Could not save the release channel. The saved setting was restored.', 'error');
    });
}

function av2SaveSecrets(form, event) {
    if (event) event.preventDefault();
    const agentId = form.dataset.agent;
    // Unraid nginx breaks multipart POSTs (see memory: feedback_unraid_multipart_hang).
    // Build a plain object that jQuery will urlencode for us.
    //
    // Field semantics (WP #736 follow-up): SEND every field EXCEPT those still
    // showing the masked '••••••••' placeholder (= set + untouched → server
    // keeps it). An empty value is sent verbatim and means "the user cleared
    // this field" → server deletes the key. Previously empty fields were
    // skipped, so clearing a secret was a silent no-op.
    const payload = { csrf_token: csrf };
    for (const [k, v] of new FormData(form)) {
        if (v === '••••••••') continue;   // untouched + set → don't touch it
        payload[k] = v;                    // '' (cleared) or a real value
    }
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=save_vault',
        method: 'POST', data: payload, dataType: 'json',
        success: function(r) {
            if (r && r.status === 'ok') {
                if (typeof swal === 'function') swal({title: 'Saved', text: 'Secrets updated for ' + agentId, type: 'success', timer: 1300, showConfirmButton: false});
                // #463: a save that changed nothing needs no restart notice.
                if (r.changed !== false && typeof av2OfferRestart === 'function') av2OfferRestart(agentId);
            } else {
                if (typeof swal === 'function') swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) {
            if (typeof swal === 'function') swal('Error', 'Failed to save secrets: ' + (xhr.responseText || xhr.statusText), 'error');
        }
    });
    return false;
}

// ── WP #736: free-form agent-level env vars + secrets (the "Variables" and
// "Secrets" sub-sections in the ENVS panel). add/edit/delete + hot-apply.

// Append a blank row to the free-form list inside the form the Add button lives in.
function av2FfAddRow(btn, kind) {
    var form = btn.closest('.av2-ff-form');
    if (!form) return;
    var list = form.querySelector('.av2-ff-list');
    if (!list) return;
    var empty = list.querySelector('.av2-ff-empty');
    if (empty) empty.remove();
    var nameInput = av2mkel('input', {
        'class': 'av2-ff-name', type: 'text',
        placeholder: kind === 'secret' ? 'SECRET_NAME' : 'VARIABLE_NAME',
        oninput: function(e){ e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g,''); }
    });
    var valInput = av2mkel('input', {
        'class': 'av2-ff-val',
        type: kind === 'secret' ? 'password' : 'text',
        'data-has-value': kind === 'secret' ? '0' : null,
        placeholder: 'value'
    });
    var delBtn = av2mkel('button', { type: 'button', 'class': 'av2-ff-del', title: 'Remove', onclick: function(){ av2FfRemoveRow(delBtn); } },
        [av2mkel('i', { 'class': 'fa fa-trash-o' })]);
    var row = av2mkel('div', { 'class': 'av2-ff-row' }, [nameInput, av2mkel('span', { 'class': 'av2-ff-eq' }, ['=']), valInput, delBtn]);
    list.appendChild(row);
    if (kind === 'secret' && window.aicliMaskEye) window.aicliMaskEye(valInput); // show-value eye (ManagerScripts.php)
    nameInput.focus();
}

function av2FfRemoveRow(btn) {
    var row = btn.closest('.av2-ff-row');
    if (row) row.remove();
}

// Collect [{name, value, hasValue}] from a free-form form's rows.
function av2FfCollect(form) {
    var out = {};
    form.querySelectorAll('.av2-ff-row').forEach(function(row) {
        var name = (row.querySelector('.av2-ff-name') || {}).value || '';
        name = name.trim();
        if (!name) return;
        var valEl = row.querySelector('.av2-ff-val');
        out[name] = valEl ? valEl.value : '';
    });
    return out;
}

// #463: a Save never restarts a session by itself. After a successful save,
// av2OfferRestart() counts the agent's running sessions and shows a notice with a
// "Restart them now" button. The button opens a confirm dialog that lists each
// session as busy or idle and restarts only the idle ones (Ctrl-C → relaunch, which
// picks up the new env via aicli-shell.sh's _aicli_load_envs). A busy session is
// never restarted from here: the server refuses it too (agent_signal_reload, no
// force). Only sessions of this agent are listed (list_sessions_for_agent).
// Spec: docs/specs/AGENT_SECRETS_SAVE_RELOAD_GUARD.md
function av2RestartNoticeBox() {
    var box = document.getElementById('av2-restart-notice');
    if (box) return box;
    box = av2mkel('div', { id: 'av2-restart-notice', style: 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:10001;max-width:90vw;background:#1f2937;color:#fff;border-radius:8px;padding:10px 14px;box-shadow:0 4px 16px rgba(0,0,0,.4);display:none;align-items:center;gap:12px' });
    document.body.appendChild(box);
    return box;
}

function av2OfferRestart(agentId) {
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_sessions_for_agent&busy=1&agentId='
              + encodeURIComponent(agentId) + '&csrf_token=' + csrf, function(r) {
        var sessions = ((r && r.sessions) || []).filter(function(s) { return s && s.id; });
        var box = av2RestartNoticeBox();
        while (box.firstChild) box.removeChild(box.firstChild);
        if (sessions.length === 0) { box.style.display = 'none'; return; }
        var n = sessions.length;
        var text = av2mkel('span', {}, ['Saved. ' + n + ' running session' + (n === 1 ? ' uses' : 's use')
            + ' the old values until ' + (n === 1 ? 'it restarts.' : 'they restart.')]);
        var go = av2mkel('button', { type: 'button', 'class': 'aicli-btn-slim',
            onclick: function() { av2ConfirmRestart(agentId, sessions); } }, ['Restart them now']);
        var close = av2mkel('button', { type: 'button', 'class': 'aicli-btn-slim', title: 'Dismiss',
            onclick: function() { box.style.display = 'none'; } }, ['Close']);
        box.appendChild(text); box.appendChild(go); box.appendChild(close);
        box.style.display = 'flex';
    });
}

// The name of a workspace for a person: the name they gave it, else the folder name.
// Never an id (a raw id means nothing to the person).
function av2SessionLabel(s) {
    var name = String((s && s.name) || '').trim();
    if (name === '') name = String((s && s.path) || '').split('/').filter(Boolean).pop() || '';
    return name !== '' ? name : 'a workspace';
}

// The result sentence of a restart: "2 restarted, 1 is busy and keeps its old values
// until you restart it." Pure, so the wording has one test.
function av2RestartSummary(done, busy, failed) {
    var parts = [];
    if (done.length > 0) parts.push(done.length + ' restarted');
    if (busy.length > 0) {
        parts.push(busy.length + (busy.length === 1
            ? ' is busy and keeps its old values until you restart it'
            : ' are busy and keep their old values until you restart them'));
    }
    if (failed.length > 0) parts.push(failed.length + ' could not be restarted');
    if (parts.length === 0) return 'No workspace was restarted.';
    var text = parts.join(', ');
    var named = busy.concat(failed);
    if (named.length > 0) text += '. Not restarted: ' + named.join(', ');
    return text + '.';
}

// Shows the result in the notice box, with a Close button. The box stays until the
// person closes it, so they can read which workspaces still run the old values.
function av2ShowRestartResult(text) {
    var box = av2RestartNoticeBox();
    while (box.firstChild) box.removeChild(box.firstChild);
    var close = av2mkel('button', { type: 'button', 'class': 'aicli-btn-slim', title: 'Dismiss',
        onclick: function() { box.style.display = 'none'; } }, ['Close']);
    box.appendChild(av2mkel('span', {}, [text]));
    box.appendChild(close);
    box.style.display = 'flex';
}

// "Restart them now": read the session list again (a session may have turned busy
// or idle since the Save), then ask. If the list cannot be read, use the list from
// the Save; the server still refuses a busy session.
function av2ConfirmRestart(agentId, savedSessions) {
    var show = function(sessions) { av2ShowRestartDialog(agentId, sessions); };
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=list_sessions_for_agent&busy=1&agentId='
              + encodeURIComponent(agentId) + '&csrf_token=' + csrf, function(r) {
        if (r && r.status === 'ok') {
            show(((r.sessions) || []).filter(function(s) { return s && s.id; }));
        } else {
            show(savedSessions);
        }
    }).fail(function() { show(savedSessions); });
}

function av2ShowRestartDialog(agentId, sessions) {
    if (sessions.length === 0) {
        av2ShowRestartResult('No workspace of this agent is running now.');
        return;
    }
    var idle = sessions.filter(function(s) { return !s.busy; });
    var busyNames = sessions.filter(function(s) { return s.busy; }).map(av2SessionLabel);
    var lines = sessions.map(function(s) {
        return '• ' + _escAttr(av2SessionLabel(s)) + ' — ' + (s.busy
            ? '<b>busy</b>: kept running; restart it yourself when it is idle'
            : 'idle: will restart');
    }).join('<br>');
    swal({
        title: 'Restart sessions?',
        text: '<div style="text-align:left;line-height:1.5">' + lines + '</div>',
        html: true,
        type: 'warning',
        showCancelButton: true,
        showConfirmButton: idle.length > 0,
        confirmButtonText: 'Restart idle sessions',
        cancelButtonText: 'Cancel',
        closeOnConfirm: true
    }, function(confirmed) {
        if (!confirmed) return;
        var restarted = [], refused = busyNames.slice(), failed = [], waiting = idle.length;
        // idle is never empty here: the confirm button is hidden when it is.
        var finish = function() {
            if (--waiting > 0) return;
            av2ShowRestartResult(av2RestartSummary(restarted, refused, failed));
        };
        idle.forEach(function(s) {
            var label = av2SessionLabel(s);
            // No force: the server checks again and refuses a session that became busy.
            $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=agent_signal_reload&id='
                      + encodeURIComponent(s.id) + '&csrf_token=' + csrf)
                .done(function(r) {
                    if (r && r.status === 'ok') restarted.push(label);
                    else if (r && r.status === 'busy') refused.push(label);
                    else failed.push(label);
                })
                .fail(function() { failed.push(label); })
                .always(finish);
        });
    });
}

function av2FfSave(form, event, action, agentId, label) {
    if (event) event.preventDefault();
    var map = av2FfCollect(form);
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=' + action + '&agentId=' + encodeURIComponent(agentId) + '&csrf_token=' + csrf,
        method: 'POST',
        data: { csrf_token: csrf, envs: JSON.stringify(map), secrets: JSON.stringify(map) },
        dataType: 'json',
        success: function(r) {
            if (r && r.status === 'ok') {
                if (typeof swal === 'function') swal({ title: 'Saved', text: label + ' updated for ' + agentId, type: 'success', timer: 1300, showConfirmButton: false });
                // #463: a save that changed nothing needs no restart notice.
                if (r.changed !== false) av2OfferRestart(agentId);
            } else {
                if (typeof swal === 'function') swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) {
            if (typeof swal === 'function') swal('Error', 'Save failed: ' + (xhr.responseText || xhr.statusText || 'network error'), 'error');
        }
    });
    return false;
}

function av2SaveAgentEnvs(form, event) {
    return av2FfSave(form, event, 'save_agent_envs', form.dataset.agent, 'Variables');
}
function av2SaveAgentSecrets(form, event) {
    return av2FfSave(form, event, 'save_agent_secrets', form.dataset.agent, 'Secrets');
}

// DOM builder helpers — avoid innerHTML with any interpolated user data.
function av2mkel(tag, attrs, children) {
    const el = document.createElement(tag);
    for (const k in (attrs || {})) {
        if (attrs[k] == null) continue;
        if (k === 'class') el.className = attrs[k];
        else if (k.startsWith('on')) el[k] = attrs[k];
        else el.setAttribute(k, attrs[k]);
    }
    (children || []).forEach(c => {
        if (c == null) return;
        el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
}

// Known option sets for tmux keys — fields with a finite value set render as
// <select> dropdowns; free-form fields (history-limit, prefix, base-index,
// default-terminal) stay as text inputs. Matches tmux's own value enumerations.
const AV2_TMUX_OPTIONS = {
    'status':            ['on', 'off'],
    'mouse':             ['on', 'off'],
    'focus-events':      ['on', 'off'],
    'allow-passthrough': ['on', 'off'],
    'bell-action':       ['any', 'none', 'current', 'other'],
};

// Short tooltips per tmux key — shown on hover of the (i) icon next to each
// row. Sourced from tmux(1) man page; kept under ~120 chars so they render
// well in native title tooltips without wrapping oddly.
const AV2_TMUX_HELP = {
    'status':            'Show the status bar at the bottom of the terminal.',
    'mouse':             'Enable mouse support: click to select pane/window, drag to resize, scroll for history.',
    'history-limit':     'Lines of scrollback kept per pane. Higher = more memory per session.',
    'prefix':            'Command prefix key (default C-b). Every tmux keybinding is triggered by this combo first.',
    'base-index':        'Index of the first window — 0 matches shell conventions, 1 matches keyboard number row.',
    'bell-action':       'Which pane triggers a bell alert: any, none, current (focused only), other (unfocused only).',
    'default-terminal':  'TERM value exported to programs (default: tmux-256color). tmux-256color enables italics and ships its terminfo bundled with the plugin; the shell falls back to xterm-256color automatically if that terminfo cannot be compiled.',
    'focus-events':      'Forward focus-gained/focus-lost escape codes to apps (needed for vim/nvim auto-reload).',
    'allow-passthrough': 'Allow OSC escape sequences to pass through tmux — enables inline images, hyperlinks.',
};

function av2LoadTmuxPanel(agentId, panel) {
    const body = panel.querySelector('.av2-tmux-form');
    if (!body) return;
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_get_agent_defaults&agentId=' + encodeURIComponent(agentId) + '&csrf_token=' + csrf, function(r) {
        body.textContent = '';
        if (r.status !== 'ok') {
            body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Failed to load: ' + (r.message || 'unknown error')]));
            return;
        }
        const agentDefs = r.settings || {};
        const builtin = r.builtin || {};
        const keys = r.allowedKeys || Object.keys(builtin);

        const help = av2mkel('p', {class: 'av2-help', style: 'margin:-4px 0 12px;'}, [
            'Changes auto-save. Rows differing from the plugin built-in default are flagged (●) — only divergent keys persist.'
        ]);
        body.appendChild(help);

        keys.forEach(function(key) {
            const b = String(builtin[key] ?? '');
            const cur = String(agentDefs[key] ?? b);
            const isMod = cur !== b;

            let field;
            if (AV2_TMUX_OPTIONS[key]) {
                // Enum field — render as a <select> with all valid options.
                field = av2mkel('select', {name: key}, AV2_TMUX_OPTIONS[key].map(opt => {
                    const o = av2mkel('option', {value: opt}, [opt]);
                    if (opt === cur) o.selected = true;
                    return o;
                }));
            } else {
                // Free-form — text input.
                field = av2mkel('input', {type: 'text', name: key, value: cur});
            }

            // Auto-save on any change. Debounced so rapid typing doesn't spam AJAX.
            const onFieldChange = () => {
                av2DiffCheck(field);
                av2ScheduleTmuxSave(body, agentId);
            };
            field.addEventListener('input', onFieldChange);
            field.addEventListener('change', onFieldChange);

            // Per-row revert button removed — users can edit the value directly
            // or use "Reset all to built-in" in the footer. Diff dot (●) on the
            // left gutter still flags divergent rows.
            const helpText = AV2_TMUX_HELP[key] || '';
            const tipBody = helpText ? (helpText + (b ? '  ·  Built-in: ' + b : '')) : ('Built-in: ' + b);
            // data-tip drives a CSS ::after tooltip (see ManagerStyles .av2-info)
            // so we don't depend on the native title timer, which can be swallowed
            // by Unraid's legacy tooltip plugins on some pages.
            const infoIcon = av2mkel('span', {
                class: 'av2-info',
                'data-tip': tipBody,
                'aria-label': tipBody,
                tabindex: '0',
            }, ['i']);
            // Epic #307 (axe label / select-name): tie the key label to its field.
            field.id = 'av2-tmux-' + String(agentId).replace(/[^a-zA-Z0-9_-]/g, '_') + '-' + key;
            const row = av2mkel('div', {class: 'av2-row' + (isMod ? ' modified' : ''), 'data-builtin': b}, [
                av2mkel('label', {'for': field.id}, [key]),
                av2mkel('div', {}, [field]),
                infoIcon,
            ]);
            body.appendChild(row);
        });

        body.appendChild(av2mkel('p', {class: 'av2-help'}, [
            'Applied every time this agent launches in any workspace. Workspaces can override any field from the workspace drawer.'
        ]));

        const resetAll = av2mkel('button', {type: 'button', class: 'av2-btn danger'}, ['Reset all to built-in']);
        resetAll.addEventListener('click', () => {
            av2ResetAllTmux(resetAll);
            av2ScheduleTmuxSave(body, agentId);
        });
        // Auto-save hint (replaces the prior manual Save button — users no longer
        // need to click anything to persist changes).
        const savedNote = av2mkel('span', {class: 'av2-save-note', 'data-role': 'save-note'}, ['Auto-saves on change']);
        body.appendChild(av2mkel('div', {class: 'av2-panel-footer'}, [savedNote, resetAll]));
        av2LoadAutoLaunchSection(agentId, body);
    }).fail(function() {
        body.textContent = '';
        body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Network error loading tmux defaults.']));
    });
}

// Debounced auto-save (500ms). Guards against rapid-fire AJAX from typing in a
// text field. A small toast-like note updates in the panel footer: saving... → saved ✓
const _av2TmuxSaveTimers = new Map();
function av2ScheduleTmuxSave(form, agentId) {
    const prior = _av2TmuxSaveTimers.get(agentId);
    if (prior) clearTimeout(prior);
    _av2TmuxSaveTimers.set(agentId, setTimeout(() => {
        _av2TmuxSaveTimers.delete(agentId);
        av2SaveAgentTmuxQuiet(form, agentId);
    }, 500));
}

// Same as av2SaveAgentTmux but updates the inline "Auto-saves on change" note
// instead of popping a SweetAlert — auto-save shouldn't interrupt the user.
function av2SaveAgentTmuxQuiet(form, agentId) {
    const note = form.querySelector('[data-role="save-note"]');
    if (note) note.textContent = 'Saving…';
    const settings = {};
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        settings[input.name] = input.value;
    });
    // Urlencoded form — Unraid nginx chokes on multipart boundaries on this
    // path, which was the root cause of the "Save failed" toast (memory:
    // feedback_unraid_multipart_hang).
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_save_agent_defaults',
        method: 'POST',
        data: { agentId: agentId, settings: JSON.stringify(settings), csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                if (note) {
                    note.textContent = 'Saved ✓';
                    note.classList.add('ok');
                    setTimeout(() => { note.textContent = 'Auto-saves on change'; note.classList.remove('ok'); }, 1800);
                }
                // Update chip state indicator (has-custom dot) based on current
                // modified-row count.
                const card = form.closest('.av2-card');
                const chip = card && card.querySelector('.av2-chip[data-target="terminal"]');
                if (chip) {
                    const modCount = form.querySelectorAll('.av2-row.modified').length;
                    chip.classList.toggle('has-custom', modCount > 0);
                }
            } else if (note) {
                note.textContent = 'Save failed';
                note.classList.add('bad');
            }
        },
        error: function() {
            if (note) { note.textContent = 'Save failed — network error'; note.classList.add('bad'); }
        }
    });
}

function av2SaveAgentTmux(btn, agentId) {
    const form = btn.closest('.av2-tmux-form');
    const settings = {};
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        settings[input.name] = input.value;
    });
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=tmux_save_agent_defaults',
        method: 'POST',
        data: { agentId: agentId, settings: JSON.stringify(settings), csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                swal({title: 'Saved', text: 'Terminal defaults for ' + agentId, type: 'success', timer: 1500, showConfirmButton: false});
                const card = btn.closest('.av2-card');
                const chip = card && card.querySelector('.av2-chip[data-target="terminal"] .av2-v');
                if (chip) {
                    let n = 0;
                    form.querySelectorAll('.av2-row.modified').forEach(function(){ n++; });
                    chip.className = 'av2-v' + (n === 0 ? ' muted' : '');
                    chip.textContent = n === 0 ? 'built-in' : (n + ' custom');
                }
            } else {
                swal('Error', (r && r.message) || 'Save failed', 'error');
            }
        },
        error: function(xhr) { swal('Error', xhr.responseText || 'Network error', 'error'); }
    });
}

function av2ResetAllTmux(btn) {
    const form = btn.closest('.av2-tmux-form');
    form.querySelectorAll('.av2-row').forEach(function(row) {
        const input = row.querySelector('input, select');
        if (!input) return;
        input.value = row.dataset.builtin ?? '';
        row.classList.remove('modified');
    });
}

// ----- Auto-Launch workspace section (appended to Terminal chip panel) -----

function av2LoadAutoLaunchSection(agentId, panelBody) {
    // Sanitise an agent id for use as an HTML attribute. Server-supplied but never
    // user-typed in practice; defensive guard so for/id pairings stay valid.
    const sanitiseId = function(s) {
        return String(s == null ? '' : s).replace(/[^a-zA-Z0-9_-]/g, '_');
    };

    // R-C1: auto-launch is now an AGENT-LEVEL setting — one toggle that governs
    // EVERY workspace of this agent (and relaunches them all after a plugin
    // upgrade), instead of a row per workspace.
    $.getJSON(
        '/plugins/unraid-aicliagents/AICliAjax.php?action=get_auto_launch&agentId=' +
        encodeURIComponent(agentId) + '&csrf_token=' + csrf,
        function(r) {
            if (r.status !== 'ok') return;

            const armed = !!r.autoLaunch;
            const count = r.workspaceCount || 0;
            const uid   = 'al-' + sanitiseId(agentId);

            const section = av2mkel('div', {class: 'av2-al-section'}, []);
            section.appendChild(av2mkel('p', {class: 'av2-al-eyebrow'}, ['⚡ Keep saved workspaces running']));
            section.appendChild(av2mkel('p', {class: 'av2-al-caption'}, [
                'When enabled, ALL of this agent’s workspaces (' + count +
                ') start after reboot and are recreated if their agent exits. Switch this off to opt out.'
            ]));

            const saveNote = av2mkel('span', {class: 'av2-save-note', style: 'display:inline-block; margin-top:8px;'}, ['']);

            const row = av2mkel('div', {class: 'av2-al-row' + (armed ? ' armed' : '')}, []);

            const chkAuto = av2mkel('input', {type: 'checkbox', id: uid});
            if (armed) chkAuto.checked = true;

            const chkFresh = av2mkel('input', {type: 'checkbox', id: uid + '-fresh'});
            if (r.freshIfNoResume) chkFresh.checked = true;

            row.appendChild(av2mkel('label', {class: 'av2-al-toggle', 'for': uid}, [
                chkAuto,
                av2mkel('span', {}, ['Restart all saved workspaces']),
            ]));

            // Epic #307: the label WRAPS the check box, so the whole label is
            // the touch target on a phone (a bare check box is 13 px).
            row.appendChild(av2mkel('div', {class: 'av2-al-fresh'}, [
                av2mkel('label', {'for': uid + '-fresh'}, [
                    chkFresh,
                    av2mkel('span', {}, ['Start fresh if no resume']),
                ]),
            ]));

            chkAuto.addEventListener('change', function() {
                if (chkAuto.checked) {
                    row.classList.add('armed');
                } else {
                    row.classList.remove('armed');
                    chkFresh.checked = false;
                }
                av2SaveAutoLaunch(agentId, chkAuto.checked, chkFresh.checked, saveNote);
            });
            chkFresh.addEventListener('change', function() {
                av2SaveAutoLaunch(agentId, chkAuto.checked, chkFresh.checked, saveNote);
            });

            section.appendChild(row);
            section.appendChild(av2mkel('p', {class: 'av2-al-caption', style: 'margin-top:4px;'}, [
                '"Start fresh" discards any saved session state and starts a brand-new session instead of resuming the previous one.'
            ]));
            section.appendChild(saveNote);
            panelBody.appendChild(section);
        }
    ).fail(function() {
        // Network error or non-200 — surface a small error line so a silent
        // miss isn't mistaken for "auto-launch unavailable".
        const errEl = av2mkel('div', {class: 'av2-help', style: 'margin-top:12px; opacity:0.6;'}, [
            'Auto-launch section failed to load.',
        ]);
        panelBody.appendChild(errEl);
    });
}

function av2SaveAutoLaunch(agentId, autoLaunch, freshIfNoResume, noteEl) {
    if (noteEl) { noteEl.textContent = 'Saving…'; noteEl.className = 'av2-save-note'; }
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=save_auto_launch',
        method: 'POST',
        data: {
            agentId:         agentId,
            autoLaunch:      autoLaunch      ? '1' : '0',
            freshIfNoResume: freshIfNoResume ? '1' : '0',
            csrf_token:      csrf,
        },
        success: function(r) {
            if (!noteEl) return;
            noteEl.textContent = (r && r.status === 'ok') ? 'Saved ✓' : 'Save failed';
            noteEl.className   = 'av2-save-note ' + ((r && r.status === 'ok') ? 'ok' : 'bad');
            setTimeout(function() { if (noteEl) noteEl.textContent = ''; }, 2000);
        },
        error: function() {
            if (noteEl) { noteEl.textContent = 'Save failed'; noteEl.className = 'av2-save-note bad'; }
        },
    });
}

function av2LoadStoragePanel(panelOrBody, agentIdMaybe) {
    // Accept either the panel itself (legacy) or a body+agentId pair. Find the
    // storage section's body element regardless.
    const body = (panelOrBody && panelOrBody.classList && panelOrBody.classList.contains('av2-storage-body'))
        ? panelOrBody
        : panelOrBody.querySelector('.av2-storage-body');
    const agentId = agentIdMaybe || (body && body.dataset.agent);
    if (!body || !agentId) return;

    // Correct action name: get_storage_status (not _metrics — that path never existed).
    // Response shape: r.agents[id] = {mounted, mount_point, dirty_mb, physical_mb, layers, layer_files, percent}.
    $.getJSON('/plugins/unraid-aicliagents/AICliAjax.php?action=get_storage_status&csrf_token=' + csrf, function(r) {
        const m = (r && r.agents && r.agents[agentId]) || {};
        const mb = (m.physical_mb != null) ? Number(m.physical_mb).toFixed(1) : '—';
        const dirty = (m.dirty_mb != null) ? Number(m.dirty_mb).toFixed(1) : '—';
        const layers = m.layers != null ? m.layers : '—';
        body.textContent = '';

        // Stats block.
        const dl = av2mkel('dl', {class: 'av2-chan-stat'}, [
            av2mkel('dt', {}, ['Footprint']),
            av2mkel('dd', {}, [String(mb) + ' MB (' + String(layers) + ' layer' + (layers === 1 ? '' : 's') + ')']),
            av2mkel('dt', {}, ['In-memory']),
            av2mkel('dd', {}, [String(dirty) + ' MB unsaved']),
            av2mkel('dt', {}, ['Mount']),
            av2mkel('dd', {}, [m.mounted ? 'mounted' : 'not mounted']),
        ]);
        body.appendChild(dl);
        body.appendChild(av2mkel('p', {style: 'margin:6px 0 0;opacity:.7;font-size:12px;'}, [
            'Footprint is the disk space this agent uses on the server. In-memory data is recent, unsaved changes that could be lost if the agent stops before they are written to permanent storage.'
        ]));

        // Show a "Persist to Flash" button when there is unsaved ZRAM data.
        // Queues a supervisor bake so the data lands on Flash even if the
        // agent is mounted and a consolidation can't run inline.
        const dirtyNum = (m.dirty_mb != null) ? Number(m.dirty_mb) : 0;
        if (dirtyNum > 0) {
            const persistBtn = av2mkel('button', {
                class: 'aicli-btn aicli-btn-warning',
                style: 'margin-top:8px; width:100%; font-size:11px;',
                type: 'button'
            }, ['Consolidate (' + String(dirty) + ' MB unsaved)']);
            persistBtn.addEventListener('click', function() {
                persistBtn.disabled = true;
                persistBtn.textContent = 'Persisting…';
                $.ajax({
                    url: '/plugins/unraid-aicliagents/AICliAjax.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'persist_agent', id: agentId, csrf_token: csrf }
                }).done(function(res) {
                    if (res && res.status === 'ok') {
                        persistBtn.textContent = '✓ Queued — refreshing…';
                        setTimeout(function() { av2LoadAgentStorage(panelOrBody, agentId); }, 3000);
                    } else {
                        persistBtn.disabled = false;
                        persistBtn.textContent = '⚠ Failed — try again';
                    }
                }).fail(function() {
                    persistBtn.disabled = false;
                    persistBtn.textContent = '⚠ Failed — try again';
                });
            });
            body.appendChild(persistBtn);
        }
    }).fail(function() {
        body.textContent = '';
        body.appendChild(av2mkel('div', {class: 'av2-help'}, ['Storage stats unavailable.']));
    });
}

// WP #748 J / Phase B follow-up (b): contextual repair / clear-halt actions
// fired from the Store card foot. The card itself is PHP-rendered with the
// state pre-baked (boot-integrity cache + halt-marker check), so these
// handlers just gate the action behind a confirm modal and call the
// pre-existing AJAX endpoints (restore_from_sibling / install_agent /
// clear_halt). On success they safeReload() so the card re-renders with
// fresh state instead of trying to surgically update the DOM.

// Internal: GET-clear any halt for an agent, idempotently. Promise-style.
// Returns a jQuery-deferred that always resolves (never rejects) so callers
// can chain without conditional logic — clear_halt is a no-op when no halt
// marker exists, and we shouldn't block a recovery click on a non-halt case.
function _av2ClearHaltIdempotent(id, reason) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    return $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php',
        type: 'POST',
        data: { action: 'clear_halt', type: 'agent', id: id, reason: reason, csrf_token: token },
        dataType: 'json'
    }).always(function() { /* swallow — idempotent precursor */ });
}

function av2RepairAgent(id, mode, btn) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    if (mode === 'restore') {
        swal({
            title: 'Restore ' + id + '?',
            text: 'Move the SAFE_BACKUP sibling layer file(s) into the active persist path and re-register them in the manifest. Recovers the agent without a re-download.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Restore',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function(confirmed) {
            if (!confirmed) return;
            $.ajax({
                url: '/plugins/unraid-aicliagents/AICliAjax.php',
                type: 'POST',
                data: { action: 'restore_from_sibling', type: 'agent', id: id, csrf_token: token },
                dataType: 'json'
            }).done(function(r) {
                if (r && r.status === 'ok') {
                    // Restore succeeded — also clear any halt so the agent
                    // mounts normally on next boot without a second click.
                    _av2ClearHaltIdempotent(id, 'restore_from_sibling_storecard').always(function() {
                        swal({ title: 'Restored', text: r.message || 'Sibling layer(s) restored.', type: 'success', timer: 1800, showConfirmButton: false });
                        setTimeout(function() { safeReload(); }, 1200);
                    });
                } else {
                    swal('Restore failed', (r && r.message) ? r.message : 'Check lifecycle log.', 'error');
                }
            }).fail(function() {
                swal('Restore failed', 'AJAX request failed. Check debug.log.', 'error');
            });
        });
        return;
    }
    if (mode === 'reinstall') {
        // Re-install at the current channel. Under J this re-bakes to a single
        // fresh `_consolidated_<dt>.sqsh` (commitChanges for $type === 'agent'
        // routes to consolidate). Pass no explicit version so the backend uses
        // @latest on whatever channel is currently saved.
        //
        // Clear any halt FIRST: installAgent → ensureAgentMounted → mount_stack.sh
        // in strict mode exits 1 on any non-healthy classification, which would
        // make a Re-install on a halted+corrupt agent fail with "Could not mount
        // agent storage". Clearing the halt up-front lets the re-install path
        // do its own classification on the fresh layer it's about to bake.
        // installVersionAgent has its own confirm modal, so we don't add one.
        if (typeof installVersionAgent !== 'function') {
            swal('Error', 'Re-install entry point not available — refresh the page and retry.', 'error');
            return;
        }
        _av2ClearHaltIdempotent(id, 'reinstall_precursor_storecard').always(function() {
            installVersionAgent(id, btn);
        });
        return;
    }
    swal('Error', 'Unknown repair mode: ' + mode, 'error');
}

function av2ClearAgentHalt(id, btn) {
    var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    swal({
        title: 'Clear halt for ' + id + '?',
        text: "Accept the current state. The mount halt will be removed and the agent will mount normally on next boot. Use this when you've understood the warning and want to proceed.",
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Clear halt',
        showLoaderOnConfirm: true,
        closeOnConfirm: false
    }, function(confirmed) {
        if (!confirmed) return;
        $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php',
            type: 'POST',
            data: { action: 'clear_halt', type: 'agent', id: id, reason: 'user_manual_override_storecard', csrf_token: token },
            dataType: 'json'
        }).done(function(r) {
            if (r && r.status === 'ok') {
                swal({ title: 'Halt cleared', text: 'The agent will mount normally on next boot.', type: 'success', timer: 1800, showConfirmButton: false });
                setTimeout(function() { safeReload(); }, 1200);
            } else {
                swal('Failed', (r && r.message) ? r.message : 'Check lifecycle log.', 'error');
            }
        }).fail(function() {
            swal('Failed', 'AJAX request failed. Check debug.log.', 'error');
        });
    });
}

function av2SaveArgs(form, event) {
    if (event) event.preventDefault();
    const agentId = form.dataset.agent;
    const args = form.querySelector('textarea[name="args"]').value.trim();
    const errEl = form.querySelector('.av2-args-error');

    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php',
        method: 'GET',
        data: { action: 'save_agent_args', agentId: agentId, args: args, csrf_token: csrf },
        success: function(r) {
            if (r && r.status === 'ok') {
                if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
                const chip = document.querySelector('.av2-card[data-agent="' + agentId + '"] .av2-chip[data-target="args"]');
                if (chip) chip.classList.toggle('has-custom', args !== '');
                swal({title: 'Saved', text: 'CLI args updated for ' + agentId, type: 'success', timer: 1400, showConfirmButton: false});
            } else {
                const msg = (r && r.message) ? r.message : 'Save failed';
                if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
                else swal('Error', msg, 'error');
            }
        },
        error: function(xhr) {
            const msg = 'Failed to save args: ' + (xhr.responseText || xhr.statusText);
            if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
            else swal('Error', msg, 'error');
        }
    });
    return false;
}

// #165 rev 2026-09-04: the all-cards bake pill (av2PollBakeState + button
// disabling) was removed on user feedback. A waiting upgrade now reports in
// the waiting agent's own install-status area via install-bg's queued
// statuses (see docs/specs/AGENT_CARD_BAKE_INDICATOR.md).

</script>
