<?php
/**
 * <module_context>
 * Description: The Boot disk space card on the Storage tab (docs/specs/BOOT_DISK_SPACE.md R1, #538).
 *   It fills <div id="aicli-boot-space-card">. It reads the last measurement with the AJAX action
 *   get_boot_space (read-only) and shows the disk bar (size, used, free) and one line per part,
 *   largest first, with a size bar, the size, and the action button of the line. A button that
 *   cannot run now is greyed and its tooltip gives the reason. The sizes come from a background
 *   job, never from the page request: while the answer says measuring:true, the card asks again
 *   every 3 seconds. "Measure now" starts a new measure (POST measure_boot_space).
 *   It defines aicliBootSpace.refresh(): every action of the card calls it when it ends, so the
 *   numbers update at once.
 *   It also gives the dialogs their shared helpers (#552): aicliBootSpace.stampHtml(answer) writes
 *   "Measured at HH:MM (N min ago)" and, when the numbers are older than 10 minutes or missing,
 *   a "Measure now" button. aicliBootSpace.measureNow(done) starts one background measure and
 *   polls get_boot_space (a read) every 3 seconds until it ends. A dialog never measures itself
 *   and never calls a measuring action in a loop.
 *   Deep link: the page address with #boot-space opens the Storage tab and scrolls to the card.
 * Dependencies: jQuery, CommonLogging.php (aicliAjax, aicliTraceId), ManagerScripts.php (switchMainTab).
 * Constraints: Atomic UI fragment. The lines come from the server (parts); the card has no list of
 *   its own. An action id with no registered dialog shows a plain "not available" message.
 * </module_context>
 */
?>
<script>
(function () {
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
    var COLORS = { home: '#ff8c00', agents: '#2196F3', leftover: '#e53935', plugin: '#78909c' };
    var state = { data: null, pollTimer: null, loadedAt: 0, loading: false };

    function esc(s) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(String(s == null ? '' : s));
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Pure: "340 MB" / "1.2 GB" / "12 KB".
    function human(bytes) {
        var b = Math.max(0, Number(bytes) || 0);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        if (b >= 1048576) return Math.round(b / 1048576) + ' MB';
        if (b >= 1024) return Math.round(b / 1024) + ' KB';
        return b + ' B';
    }

    // Pure: "14:05" from an epoch in seconds.
    function clock(epoch) {
        var d = new Date((Number(epoch) || 0) * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return p(d.getHours()) + ':' + p(d.getMinutes());
    }

    // Pure: the use level of the disk: ok | early | warn | critical. The server gives it
    // (BootSpaceService::diskLevel, #755). An old measurement file has none, so the card works it out
    // with the same limits (80, 85 and 95 percent).
    function levelOf(disk, pct) {
        if (disk && /^(ok|early|warn|critical)$/.test(disk.level || '')) return disk.level;
        return pct >= 95 ? 'critical' : (pct >= 85 ? 'warn' : (pct >= 80 ? 'early' : 'ok'));
    }
    function diskPct(disk) {
        if (!disk || !disk.size) return 0;
        return Math.max(0, Math.min(100, Math.round((disk.used / disk.size) * 100)));
    }

    // Pure: the sentence under the disk bar when the disk is filling up. '' at the level ok.
    function levelText(level, pct) {
        if (level === 'early') return 'The boot disk is ' + pct + '% full. Free some space soon. The lines below show what uses it.';
        if (level === 'warn') return 'The boot disk is ' + pct + '% full. Free space now. The lines below show what uses it.';
        if (level === 'critical') return 'The boot disk is almost full (' + pct + '%). Agents may fail to save. Free space now.';
        return '';
    }

    // Pure: the HTML of the disk bar.
    function diskHtml(disk) {
        if (!disk || !disk.size) return '<div data-testid="boot-space-disk" class="aicli-bs-span" style="font-size:11px; opacity:0.6;">The disk size is not known yet.</div>';
        var pct = diskPct(disk);
        var level = levelOf(disk, pct);
        var color = { critical: '#e53935', warn: '#ff8c00', early: '#e6a23c', ok: '#4caf50' }[level];
        var warnText = levelText(level, pct);
        var warnHtml = warnText
            ? '<div data-testid="boot-space-level" data-level="' + level + '" role="status" class="aicli-bs-span" style="font-size:12px; color:' + color + ';"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> ' + esc(warnText) + '</div>'
            : '';
        var where = [disk.mount, disk.fstype].filter(Boolean).join(', ');
        // The disk row uses the same grid as the lines: its bar sits in the bar column, so its left and
        // right edges are the edges of every line bar (#794). The wrapper has display:contents.
        return '<div data-testid="boot-space-disk" class="aicli-bs-line aicli-bs-diskline">' +
            '<div class="aicli-bs-c-name">Boot disk' + (where ? ' (' + esc(where) + ')' : '') + '</div>' +
            '<div class="aicli-bs-c-bar"><div class="stat-bar-wrap" data-testid="boot-space-disk-bar"><div class="stat-bar-fill" style="width:' + pct + '%; background:' + color + ';"></div>' +
            '<div class="stat-bar-text">' + pct + '%</div></div></div>' +
            '<div class="aicli-bs-c-sum"><b>' + human(disk.used) + '</b> used of ' + human(disk.size) + ' &middot; <b>' + human(disk.free) + '</b> free</div>' +
            warnHtml + '</div>';
    }

    // Pure: the button of one action. A disabled action keeps its reason in the tooltip.
    // The native button look and the 44 px tap area come from .aicli-btn-slim (Phase 6, S1).
    function buttonHtml(part, a, migrationBusy) {
        var off = !a.enabled || migrationBusy;
        var reason = migrationBusy ? 'Wait for the storage migration to finish.' : (a.reason || 'Not available now.');
        return '<button type="button" class="aicli-bs-act aicli-btn-slim" data-testid="boot-space-action" data-action-id="' + esc(a.id) + '" data-part-key="' + esc(part.key) + '"' +
            (off ? ' disabled aria-disabled="true" title="' + esc(reason) + '"' : ' title="' + esc(a.label) + '"') +
            '>' + esc(a.label) + '</button>';
    }

    // Pure: the small (i) next to the label of a line, and its panel. The note of the line, its (i) text
    // and the link to a setting are in the panel. Empty when the line has none of them.
    function lineHelpHtml(part) {
        var paras = '';
        if (part.note) paras += '<p>' + esc(part.note) + '</p>';
        if (part.info) paras += '<p>' + esc(part.info) + '</p>';
        if (part.setting_link) {
            paras += '<p><a href="#" class="aicli-bs-setting" data-setting="' + esc(part.setting_link) + '" data-testid="boot-space-setting-link">Go to setting</a></p>';
        }
        if (!paras) return { btn: '', panel: '' };
        var cid = 'bs-' + String(part.key).toLowerCase().replace(/[^a-z0-9-]/g, '-');
        return {
            btn: '<button type="button" class="aicli-help-btn aicli-bs-info" data-aicli-help="' + cid + '" data-testid="boot-space-info"' +
                ' aria-label="About ' + esc(part.label) + '" aria-expanded="false" aria-controls="aicli-help-' + cid + '">' +
                '<i class="fa fa-info-circle" aria-hidden="true"></i></button>',
            panel: '<div class="aicli-help-panel" id="aicli-help-' + cid + '" data-testid="help-panel-' + cid + '" role="note" hidden>' + paras + '</div>'
        };
    }

    // Pure: the HTML of one line.
    function lineHtml(part, maxBytes, migrationBusy) {
        var w = maxBytes > 0 ? Math.max(part.bytes > 0 ? 2 : 0, Math.round((part.bytes / maxBytes) * 100)) : 0;
        var color = COLORS[part.group] || '#999';
        // Two fixed action slots (#794): one button sits in the right slot, so it lines up with the right
        // button of a pair. A third button, if a line ever has one, joins the right slot.
        var btns = [];
        if (part.action) btns.push(buttonHtml(part, part.action, migrationBusy));
        (part.extra_actions || []).forEach(function (a) { btns.push(buttonHtml(part, a, migrationBusy)); });
        var slotA = btns.length > 1 ? btns[0] : '';
        var slotB = btns.length > 1 ? btns.slice(1).join(' ') : (btns[0] || '');
        // Phase 6: the note, the (i) text (R8, #724) and the link to a setting are behind one (i).
        var help = lineHelpHtml(part);
        return '<div class="aicli-bs-line" data-testid="boot-space-line" data-key="' + esc(part.key) + '">' +
            '<div class="aicli-bs-c-name"><span style="font-weight:600;">' + esc(part.label) + '</span>' + help.btn + help.panel + '</div>' +
            '<div class="aicli-bs-c-bar"><div class="stat-bar-wrap" data-testid="boot-space-bar" style="height:10px;"><div class="stat-bar-fill" style="width:' + w + '%; background:' + color + ';"></div></div></div>' +
            '<div class="aicli-bs-c-size" data-testid="boot-space-size">' + human(part.bytes) + '</div>' +
            '<div class="aicli-bs-c-slot-a">' + slotA + '</div>' +
            '<div class="aicli-bs-c-slot-b">' + slotB + '</div>' +
        '</div>';
    }
    // Pure: the warning about the agent temporary folders in RAM (AGENT_TEMP_FOLDER.md R4). '' when there is no warning.
    // The numbers come from the background measure (data.agent_tmp). The button opens the dialog of fragment 60.
    function tmpWarnHtml(t, migrationBusy) {
        if (!t || !t.warn) return '';
        var closed = Number(t.closed_bytes) || 0;
        var canClean = closed > 0 && !migrationBusy;
        return '<div data-testid="boot-space-tmp-warn" class="aicli-bs-span" style="padding:10px; margin:6px 0; border:1px solid #ff8c00; border-radius:4px; background:rgba(255,140,0,0.08); font-size:12px;">' +
            '<div style="font-weight:600; margin-bottom:3px;"><i class="fa fa-exclamation-triangle"></i> Temporary files of agents use ' + human(t.ram_bytes) + ' of RAM</div>' +
            '<div style="opacity:0.85; margin-bottom:6px;">The warning limit is ' + human((Number(t.warn_mb) || 0) * 1048576) + '. Sessions that no longer run hold ' + human(closed) + ' of it. A large temporary folder in memory can make typing slow. The setting Agent temporary files (Configuration tab) can move new sessions to a pool.</div>' +
            '<button type="button" class="aicli-bs-tmp-act aicli-btn-slim" data-testid="boot-space-tmp-clean"' + (canClean ? '' : ' disabled aria-disabled="true" title="' + (migrationBusy ? 'Wait for the storage migration to finish.' : 'No closed session holds temporary files.') + '"') +
            '>Clean up temporary files of closed sessions</button></div>';
    }

    // Pure: the whole card body. `data` is the answer of get_boot_space.
    function cardHtml(data) {
        var parts = (data && data.parts) || [];
        var max = 0;
        parts.forEach(function (p) { if (p.bytes > max) max = p.bytes; });
        var measuring = !!(data && data.measuring);
        var migration = data && data.storage_migration;
        var migrationBusy = !!(migration && ['queued', 'running', 'interrupted'].indexOf(migration.status) !== -1);
        var stamp;
        if (data && data.has_data) stamp = 'Measured at <b data-testid="boot-space-measured">' + clock(data.measured_at) + '</b>';
        else stamp = '<span data-testid="boot-space-measured">Not measured yet.</span>';
        if (measuring) stamp += ' &middot; <i class="fa fa-spinner fa-spin"></i> measuring now&hellip;';
        var rows = parts.length
            ? parts.map(function (p) { return lineHtml(p, max, migrationBusy); }).join('')
            : '<div class="aicli-bs-span" style="font-size:12px; opacity:0.7; padding:8px 0;">' + (measuring ? 'The sizes are being measured. This takes a few seconds.' : 'No sizes yet. Press Measure now.') + '</div>';
        var migrationBanner = '';
        if (migrationBusy) {
            var progress = Number(migration.total) > 0 ? (Number(migration.completed || 0) + ' of ' + Number(migration.total) + ' storage items complete.') : 'The migration is starting.';
            var stopped = migration.status === 'interrupted';
            // Say which kind converts: the migration status names it (homes or agents).
            var kindWord = migration.kind === 'homes' ? 'Home storage' : (migration.kind === 'agents' ? 'Agent storage' : 'Storage');
            var recovery = stopped ? '<div style="margin-top:6px;"><button type="button" class="aicli-btn-slim" data-testid="boot-space-migration-continue" data-recovery="continue">Continue</button> <button type="button" class="aicli-btn-slim" data-testid="boot-space-migration-rollback" data-recovery="rollback">Put back</button></div>' : '';
            migrationBanner = '<div data-testid="boot-space-migration" role="status" aria-live="polite" class="aicli-bs-span" style="padding:10px 12px; margin:6px 0; border:1px solid #e6a23c; border-radius:4px; background:rgba(230,162,60,.12); font-size:12px;"><strong>' + (stopped ? kindWord + ' migration stopped' : (migration.status === 'queued' ? kindWord + ' migration starting' : kindWord + ' migration in progress')) + '</strong><div>' + esc(migration.message || 'Workspaces and storage actions are paused until this finishes.') + '</div><div>' + esc(progress) + '</div>' + (Number(migration.total) > 0 ? '<progress max="' + Number(migration.total) + '" value="' + Number(migration.completed || 0) + '" aria-label="Storage migration progress" style="width:100%; margin-top:6px;"></progress>' : '') + recovery + '</div>';
        }
        // Phase 6: the card itself (header, status, (i), menu) is in ManagerStorageTab.php. This is its body.
        // The disk row, the banners and every line share ONE grid (.aicli-bs-grid, ManagerStyles.php).
        return '<div class="aicli-bs-grid" data-testid="boot-space-grid">' +
                diskHtml(data && data.disk) +
                migrationBanner +
                tmpWarnHtml(data && data.agent_tmp, migrationBusy) +
                rows +
                '</div>' +
                '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-top:10px; font-size:11px; opacity:0.85;">' +
                    '<span>' + stamp + '</span>' +
                    '<span>' +
                    '<button type="button" id="aicli-bs-restore" class="aicli-btn-slim" data-testid="boot-space-restore" title="Put files of an archive back into a home. Nothing is overwritten without your choice."' + (migrationBusy ? ' disabled aria-disabled="true"' : '') + '>Restore from an archive&hellip;</button> ' +
                    '<button type="button" id="aicli-bs-measure" class="aicli-btn-slim" data-testid="boot-space-measure-now"' + ((measuring || migrationBusy) ? ' disabled title="Wait for the storage migration to finish."' : '') + '>Measure now</button>' +
                    '</span>' +
                '</div>';
    }

    // Pure: the status line of the card: "5.7 GB used of 15 GB \u00b7 8.6 GB free".
    function statusText(data) {
        var d = data && data.disk;
        if (!d || !d.size) return 'Not measured yet';
        return human(d.used) + ' used of ' + human(d.size) + ' \u00b7 ' + human(d.free) + ' free';
    }

    function draw(data) {
        state.data = data;
        var el = document.getElementById('aicli-boot-space-card');
        if (window.aicliCards) {
            window.aicliCards.setStatus('boot-space', statusText(data));
            var dsk = data && data.disk;
            var lvl = dsk && dsk.size ? levelOf(dsk, diskPct(dsk)) : 'ok';
            var tmpWarn = !!(data && data.agent_tmp && data.agent_tmp.warn);
            // The card header warns at 80 percent (early) and above, and for the RAM warning (#755).
            window.aicliCards.setWarning('boot-space', tmpWarn || lvl !== 'ok',
                lvl !== 'ok' ? 'Disk ' + diskPct(dsk) + '% full' : 'Warning');
        }
        if (!el) return;
        var html = cardHtml(data);
        // The same answer draws nothing again, so an open (i) panel and a focused button stay.
        if (el.__aicliHtml === html) return;
        el.__aicliHtml = html;
        el.innerHTML = html;
        if (window.aicliHelp) window.aicliHelp.attach(el);
    }

    function stopPoll() { if (state.pollTimer) { clearTimeout(state.pollTimer); state.pollTimer = null; } }

    function load() {
        if (state.loading) return;
        state.loading = true;
        aicliAjax('get_boot_space', {}, function (r) {
            state.loading = false;
            state.loadedAt = Date.now();
            if (!r || r.status !== 'ok') return;
            draw(r);
            stopPoll();
            if (r.measuring || (r.storage_migration && ['queued', 'running'].indexOf(r.storage_migration.status) !== -1)) state.pollTimer = setTimeout(load, 3000);
            // AGENT_STORE_CARD_HEADER.md R16: tell the Agent Store cards that the figures are fresh ("Measure now" ended).
            if (!r.measuring) { try { document.dispatchEvent(new CustomEvent('aicli:boot-space-measured', { detail: { measured_at: r.measured_at } })); } catch (e) { /* no event support */ } }
        }).fail(function (x, s, e) {
            // R-175: a failed read ends the "measuring" wait, so Measure now works again.
            state.loading = false;
            stopPoll();
            if (state.data && state.data.measuring) { state.data.measuring = false; draw(state.data); }
            if (typeof aicliAjaxDefaultFail === 'function') aicliAjaxDefaultFail(x, s, e);
        });
    }

    // A POST with the action and the CSRF token in the body (R-176: no token in a URL).
    function post(action, params, done) {
        var token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
        var headers = {};
        if (typeof aicliTraceId === 'function') headers['X-Aicli-Trace'] = aicliTraceId();
        var xhr = $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php?action=' + encodeURIComponent(action),
            method: 'POST', dataType: 'json', headers: headers,
            data: Object.assign({ csrf_token: token }, params || {})
        });
        if (done) xhr.done(done);
        // R-175: a failed change request shows its reason unless the caller handles it.
        return typeof aicliAjaxWatch === 'function' ? aicliAjaxWatch(xhr) : xhr;
    }
    reg.post = post;

    // ---- helpers for the dialogs (#552) ----------------------------------------------------

    // Pure: "Measured at 14:05 (3 min ago)" from a dialog answer (measured_at, age, has_data, partial).
    function ageText(r) {
        if (!r || !r.has_data) return 'Not measured yet.';
        var a = Math.max(0, Number(r.age) || 0);
        var ago = a < 60 ? 'less than a minute ago' : (a < 3600 ? Math.round(a / 60) + ' min ago' : Math.round(a / 3600) + ' h ago');
        return 'Measured at ' + clock(r.measured_at) + ' (' + ago + ')' + (r.partial ? '. The measure stopped at its time limit, so some sizes may be missing.' : '');
    }
    reg.ageText = ageText;

    // Pure: the line under a dialog. A "Measure now" button shows when the numbers are older than
    // 10 minutes or missing.
    reg.stampHtml = function (r) {
        var stale = !r || !r.has_data || !!r.stale;
        var migration = r && r.storage_migration;
        var migrationBusy = !!(migration && ['queued', 'running', 'interrupted'].indexOf(migration.status) !== -1);
        var h = '<div data-testid="bootspace-dialog-stamp" data-stale="' + (stale ? '1' : '0') + '" style="margin-top:8px; font-size:11px; opacity:0.85;">' + esc(ageText(r));
        if (r && r.measuring) h += ' <i class="fa fa-spinner fa-spin"></i> measuring now&hellip;';
        if (migrationBusy) h += ' Storage actions are paused until the migration finishes.';
        if (stale && !(r && r.measuring) && !migrationBusy) {
            h += ' <button type="button" class="aicli-bs-dialog-measure aicli-btn-slim" data-testid="bootspace-dialog-measure-now">Measure now</button>';
        }
        return h + '</div>';
    };

    // Start ONE background measure, then poll get_boot_space (a read) every 3 seconds until it
    // ends or 6 minutes pass. done(answer) gets the last get_boot_space answer, or null.
    reg.measureNow = function (done) {
        function poll(n) {
            setTimeout(function () {
                aicliAjax('get_boot_space', {}, function (r) {
                    if (r && r.measuring && n < 120) { poll(n + 1); return; }
                    done(r && r.status === 'ok' ? r : null);
                }).fail(function (x, s, e) {
                    if (typeof aicliAjaxDefaultFail === 'function') aicliAjaxDefaultFail(x, s, e);
                    done(null);
                });
            }, 3000);
        }
        post('measure_boot_space', {}, function () { poll(0); }).fail(function (x, s, e) {
            if (typeof aicliAjaxDefaultFail === 'function') aicliAjaxDefaultFail(x, s, e);
            done(null);
        });
    };

    // A dialog sets aicliBootSpace.reopen = fn before it shows; the Measure now button of the dialog
    // measures once, closes the dialog and opens it again with the new numbers.
    $(document).on('click', '.aicli-bs-dialog-measure', function () {
        var btn = $(this), again = reg.reopen;
        btn.prop('disabled', true).text('Measuring\u2026');
        reg.measureNow(function () {
            if (typeof window.swal === 'function' && typeof window.swal.close === 'function') window.swal.close();
            load();
            if (typeof again === 'function') setTimeout(again, 400);
        });
    });

    // Every action of the card calls this when it ends: a new measure starts at once.
    reg.refresh = function () {
        post('measure_boot_space', {}, function () { stopPoll(); setTimeout(load, 400); })
            .fail(function (x, s, e) {
                // R-175: the request did not reach the server. Measure now works again and the reason shows.
                $('#aicli-bs-measure').prop('disabled', false);
                if (typeof aicliAjaxDefaultFail === 'function') aicliAjaxDefaultFail(x, s, e);
            });
    };

    function storageTabVisible() {
        var t = document.getElementById('tab-storage');
        return !!t && t.classList.contains('active');
    }

    function maybeLoad() {
        if (!storageTabVisible()) return;
        if (Date.now() - state.loadedAt < 30000 && state.data) return;
        load();
    }

    $(document).on('click', '#aicli-bs-measure', function () {
        $(this).prop('disabled', true);
        reg.refresh();
    });

    // "Restore from an archive…" (R8, #755): the dialog is the action of fragment 80-restore-boot-data.php.
    $(document).on('click', '#aicli-bs-restore', function () {
        if (this.getAttribute('aria-disabled') === 'true') return;
        var fn = reg.actions['restore_boot_data'];
        var homes = [];
        ((state.data && state.data.parts) || []).forEach(function (p) {
            if (p.group === 'home' && p.user && homes.indexOf(p.user) === -1) homes.push(p.user);
        });
        if (typeof fn === 'function') fn({ homes: homes });
        else if (typeof window.swal === 'function') swal('Not available', 'This action is not available on this page.', 'info');
    });

    $(document).on('click', '#aicli-boot-space-card [data-recovery]', function () {
        if (typeof window.aicliRecoverBackendMigration === 'function') {
            window.aicliRecoverBackendMigration(this.getAttribute('data-recovery'));
        }
    });

    $(document).on('click', '#aicli-boot-space-card .aicli-bs-act', function () {
        if (this.getAttribute('aria-disabled') === 'true') return;
        var key = this.getAttribute('data-part-key');
        var id = this.getAttribute('data-action-id');
        var part = null;
        ((state.data && state.data.parts) || []).forEach(function (p) { if (p.key === key) part = p; });
        if (!part) return;
        var fn = reg.actions[id];
        if (typeof fn !== 'function') {
            if (typeof window.swal === 'function') swal('Not available', 'This action is not available on this page.', 'info');
            return;
        }
        fn(part);
    });

    $(document).on('click', '#aicli-boot-space-card .aicli-bs-setting', function (e) {
        e.preventDefault();
        var name = this.getAttribute('data-setting');
        var btn = $('.aicli-tab-btn[onclick*="\'config\'"]');
        if (btn.length && typeof switchMainTab === 'function') switchMainTab('config', btn[0]);
        setTimeout(function () {
            var f = document.querySelector('[name="' + name + '"]');
            if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); if (f.focus) f.focus(); }
        }, 100);
    });

    // The last answer of get_boot_space (the fragments read it).
    reg.data = function () { return state.data; };

    // Test seams.
    reg.tmpWarnHtml = tmpWarnHtml;
    reg.cardHtml = cardHtml;
    reg.statusText = statusText;
    reg.human = human;
    reg.load = load;
    reg.diskHtml = diskHtml;
    reg.levelOf = levelOf;

    $(function () {
        $(document).on('click', '.aicli-tab-btn', function () { setTimeout(maybeLoad, 60); });
        maybeLoad();
        setInterval(function () { if (storageTabVisible() && !document.hidden) load(); }, 60000);
        // Deep link from the warning: #boot-space opens the Storage tab at this card.
        if (window.location.hash === '#boot-space') {
            setTimeout(function () {
                var btn = $('.aicli-tab-btn[onclick*="\'storage\'"]');
                if (btn.length && typeof switchMainTab === 'function') switchMainTab('storage', btn[0]);
                load();
                var el = document.getElementById('aicli-boot-space-card');
                if (el && el.scrollIntoView) el.scrollIntoView();
            }, 400);
        }
    });
})();
</script>
