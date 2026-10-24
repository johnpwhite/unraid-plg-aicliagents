<?php
/**
 * <module_context>
 * Description: The buttons "Archive…" and "Remove" on the lines for the quarantine folder, the data of
 *   deleted OpenCode workspaces and large Codex session logs (docs/specs/BOOT_DISK_SPACE.md R8, #724).
 *   It registers aicliBootSpace.actions['archive_boot_data'] and aicliBootSpace.actions['remove_boot_data'].
 *   Archive asks for a pool and for an existing folder in it. The plugin puts the files in
 *   <pool>/<folder>/aicliagents-archive/<host>/<date>/. Remove asks for a confirm that names the items.
 *   Both send the POST action boot_archive_start. The server checks the request again and queues a
 *   background job; the Activity tray shows its progress.
 *   `part` carries: kind, user, label, bytes, units, names.
 * Dependencies: jQuery, SweetAlert, 00-card.php (aicliBootSpace.post, refresh, stampHtml), aicliAjax.
 * Constraints: Atomic UI fragment. It measures nothing: the sizes come from the last measurement.
 * </module_context>
 */
?>
<script>
(function () {
    'use strict';
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function human(b) {
        b = Number(b) || 0;
        var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < 4) { b /= 1024; i++; }
        return (i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
    }
    function fail(msg) {
        if (typeof window.swal === 'function') swal('Not done', msg || 'Unknown error. Check the Debug Console.', 'error');
    }

    // Pure: the list of item names for a confirm. At most the names the measurement kept.
    function namesHtml(part) {
        var names = (part && part.names) || [];
        var units = Number(part && part.units) || names.length;
        var li = names.map(function (n) { return '<li><code>' + esc(n) + '</code></li>'; }).join('');
        var more = units > names.length ? '<li>and ' + (units - names.length) + ' more</li>' : '';
        return '<ul style="margin:0 0 8px 18px; padding:0; max-height:140px; overflow:auto;" data-testid="boot-archive-names">' + li + more + '</ul>';
    }

    // Pure: the text of the Remove confirm. It names the items.
    function removeHtml(part) {
        var what = esc(part.label || part.kind);
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="remove-boot-data-dialog">' +
            '<div style="margin-bottom:8px;">These items are removed from the boot disk <b>for good</b>. Nothing is copied first. ' +
            'Items that a process uses stay.</div>' +
            '<div style="margin-bottom:4px;"><b>' + what + '</b> &mdash; ' + (Number(part.units) || 0) + ' item(s), <b>' + human(part.bytes) + '</b>:</div>' +
            namesHtml(part) +
            '<div>This frees about <b>' + human(part.bytes) + '</b> on the boot disk. Use Archive&hellip; to keep a copy.</div></div>';
    }
    reg.removeBootDataHtml = removeHtml;   // test seam

    // Pure: where the files go, shown under the pickers.
    function destText(pool, folder, plan) {
        if (!pool || !folder) return 'Pick a pool and a folder that already exists in it.';
        return pool + '/' + folder.replace(/^\/+|\/+$/g, '') + '/' + plan.archive_folder + '/' + plan.host + '/' + plan.date + '/';
    }
    reg.archiveDestText = destText;   // test seam

    // Pure: the Archive dialog.
    function archiveHtml(part, plan) {
        var pools = plan.pools || [];
        var saved = plan.folder || '';
        var poolPath = '', folder = '';
        pools.forEach(function (p) {
            if (saved.indexOf(p.path + '/') === 0) { poolPath = p.path; folder = saved.substring(p.path.length + 1); }
        });
        if (!poolPath && pools.length) poolPath = pools[0].path;
        var opts = pools.map(function (p) {
            return '<option value="' + esc(p.path) + '"' + (p.path === poolPath ? ' selected' : '') + '>' + esc(p.path) + ' (' + human(p.free_bytes) + ' free)</option>';
        }).join('');
        var noPool = !pools.length;
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="archive-boot-data-dialog">' +
            '<div style="margin-bottom:8px;">Move <b>' + esc(part.label || part.kind) + '</b> (' + (Number(plan.units) || 0) + ' item(s), <b>' + human(plan.bytes) + '</b>) ' +
            'to a folder on a pool. Each file is copied, checked with SHA-256, listed in a manifest file, and only then removed from the boot disk. ' +
            'Items that a process uses stay.' +
            (part.kind === 'superseded_layers' ? ' A newer merged layer holds this data. The plugin does not put an archived image back: the copy is for safekeeping only.' : '') +
            '</div>' +
            '<label style="display:block;">Pool</label>' +
            '<select id="aicli-ba-pool" style="width:100%; margin-bottom:6px;"' + (noPool ? ' disabled' : '') + '>' + opts + '</select>' +
            '<label style="display:block;">Folder in the pool (it must already exist)</label>' +
            '<input type="text" id="aicli-ba-folder" value="' + esc(folder) + '" placeholder="for example backups" style="width:100%; margin-bottom:6px;"' + (noPool ? ' disabled' : '') + '>' +
            '<div style="font-size:11px; opacity:0.8; margin-bottom:6px;">The plugin makes only the folder <code>' + esc(plan.archive_folder) + '</code> inside it. ' +
            'It never makes a new top-level folder on a pool.</div>' +
            '<div style="font-size:11px;" data-testid="archive-boot-data-dest">Files go to: <code id="aicli-ba-dest">' + esc(destText(poolPath, folder, plan)) + '</code></div>' +
            (noPool ? '<div style="margin-top:8px; color:#e53935;">No pool is mounted. Start the array, then try again.</div>' : '') +
            (typeof reg.stampHtml === 'function' ? reg.stampHtml(plan) : '') +
            '</div>';
    }
    reg.archiveBootDataHtml = archiveHtml;   // test seam

    function start(part, mode, folder, done) {
        reg.post('boot_archive_start', { kind: part.kind, user: part.user, mode: mode, folder: folder || '' }, function (r) {
            if (r && r.status === 'queued') { done(true, r); return; }
            done(false, r);
        }).fail(function () { done(false, null); });
    }

    function started(mode) {
        if (typeof window.swal === 'function') {
            swal({ title: mode === 'archive' ? 'Archive started' : 'Remove started', text: 'Watch the activity tray for progress.', type: 'success', timer: 3000, showConfirmButton: false });
        }
        if (typeof reg.refresh === 'function') setTimeout(reg.refresh, 4000);
    }

    reg.actions['archive_boot_data'] = function (part) {
        part = part || {};
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        aicliAjax('boot_archive_plan', { kind: part.kind, user: part.user }, function (plan) {
            if (!plan || plan.status !== 'ok') { fail(plan && plan.message); return; }
            reg.reopen = function () { reg.actions['archive_boot_data'](part); };
            swal({
                title: 'Archive ' + plan.title.toLowerCase() + '?',
                text: archiveHtml(part, plan),
                html: true, type: 'info', showCancelButton: true,
                confirmButtonText: 'Archive', cancelButtonText: 'Cancel',
                closeOnConfirm: false, showLoaderOnConfirm: true
            }, function (ok) {
                if (!ok) return;
                var pool = $('#aicli-ba-pool').val() || '';
                var folder = ($('#aicli-ba-folder').val() || '').trim().replace(/^\/+|\/+$/g, '');
                if (!pool || !folder) { fail('Pick a pool and a folder that already exists in it.'); return; }
                start(part, 'archive', pool + '/' + folder, function (okQ, r) {
                    if (okQ) started('archive'); else fail(r && r.message);
                });
            });
            // The line under the pickers follows the pickers.
            $(document).off('input.baDest change.baDest').on('input.baDest change.baDest', '#aicli-ba-pool, #aicli-ba-folder', function () {
                $('#aicli-ba-dest').text(destText($('#aicli-ba-pool').val(), ($('#aicli-ba-folder').val() || '').trim(), plan));
            });
        });
    };

    // Pure: the part of the archive dialog for the old layer images. They ride on the Left over line
    // (part.superseded: bytes, units, files, names). The job has the kind superseded_layers and the user "all".
    function layerPart(leftover) {
        var s = (leftover && leftover.superseded) || {};
        return { key: 'archive:superseded_layers:all', kind: 'superseded_layers', user: 'all', label: 'old layer images',
                 bytes: Number(s.bytes) || 0, units: Number(s.units) || 0, files: Number(s.files) || 0, names: s.names || [] };
    }
    reg.layerPart = layerPart;   // test seam

    reg.actions['archive_superseded'] = function (leftover) {
        var p = layerPart(leftover);
        if (!p.units) { fail('No old layer image is left over.'); return; }
        reg.actions['archive_boot_data'](p);
    };

    reg.actions['remove_boot_data'] = function (part) {
        part = part || {};
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        swal({
            title: 'Remove ' + (part.label || 'these items') + '?',
            text: removeHtml(part),
            html: true, type: 'warning', showCancelButton: true,
            confirmButtonText: 'Remove', cancelButtonText: 'Cancel', confirmButtonColor: '#b35900',
            closeOnConfirm: false, showLoaderOnConfirm: true
        }, function (ok) {
            if (!ok) return;
            start(part, 'remove', '', function (okQ, r) {
                if (okQ) started('remove'); else fail(r && r.message);
            });
        });
    };
})();
</script>
